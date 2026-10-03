<?php
/**
 * Jellyfin Invite Manager
 * Gestion des invitations et des comptes temporaires pour Jellyfin.
 *
 * IMPORTANT – Avant de déployer :
 * 1. Remplace les valeurs de configuration ci-dessous.
 * 2. Génère un hash de mot de passe admin avec :
 *    php -r "echo password_hash('TON_MOT_DE_PASSE', PASSWORD_DEFAULT);"
 * 3. Génère un secret cookie aléatoire (ex. : openssl rand -base64 32)
 * 4. Place ce fichier derrière un reverse-proxy HTTPS de préférence.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURATION ---
// URL de ton instance Jellyfin (ex. : http://jellyfin:8096 ou https://jellyfin.example.com)
define('JELLYFIN_URL', 'http://jellyfin:8096');

// Clé API Jellyfin (Dashboard → API Keys)
define('JELLYFIN_API_KEY', 'REMPLACE_MOI_PAR_TA_CLE_API');

// Hash du mot de passe administrateur (généré avec password_hash)
define('ADMIN_PASSWORD_HASH', 'REMPLACE_MOI_PAR_UN_HASH_password_hash');

// Secret utilisé pour signer le cookie d’authentification admin
define('ADMIN_COOKIE_SECRET', 'REMPLACE_MOI_PAR_UN_SECRET_ALEATOIRE_LONG');

// --- INITIALISATION BASE DE DONNÉES ---
$db = new PDO('sqlite:' . __DIR__ . '/invites.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE,
    duration_days INTEGER,
    used INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS users_expiration (
    jellyfin_id TEXT PRIMARY KEY,
    username TEXT,
    expire_at DATETIME
)");

$message = '';
$action = $_GET['action'] ?? 'join';

// --- FONCTION ENVOI REQUÊTE JELLYFIN ---
function jellyfin_request($endpoint, $data = [], $method = 'POST') {
    $ch = curl_init(JELLYFIN_URL . $endpoint);
    $headers = [
        'Authorization: MediaBrowser Token="' . JELLYFIN_API_KEY . '"',
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15
    ];
    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error) {
        return ['code' => 0, 'data' => null, 'error' => $error];
    }
    return [
        'code' => $httpCode,
        'data' => json_decode($response, true)
    ];
}

// --- Applique les restrictions sur un nouvel utilisateur ---
function apply_user_restrictions($userId) {
    $foldersRes = jellyfin_request('/Library/VirtualFolders', [], 'GET');
    $folders = $foldersRes['data'] ?? [];
    $enabledFolders = [];
    foreach ($folders as $folder) {
        $name = $folder['Name'] ?? $folder['name'] ?? '';
        $id   = $folder['ItemId'] ?? $folder['Id'] ?? $folder['itemId'] ?? null;
        // Exclure le dossier "Jeux" (modifie selon tes besoins)
        if ($id && strcasecmp(trim($name), 'Jeux') !== 0) {
            $enabledFolders[] = $id;
        }
    }
    $policy = [
        'AuthenticationProviderId'   => 'Jellyfin.Server.Implementations.Users.DefaultAuthenticationProvider',
        'PasswordResetProviderId'    => 'Jellyfin.Server.Implementations.Users.DefaultPasswordResetProvider',
        'EnableContentDownloading'   => false,
        'EnableAllFolders'           => false,
        'EnabledFolders'             => $enabledFolders,
        'MaxActiveSessions'          => 2,
        'IsHidden'                   => true,
        'IsAdministrator'            => false,
        'IsDisabled'                 => false,
        'EnableMediaPlayback'        => true,
        'EnableRemoteAccess'         => true,
        'EnableLiveTvAccess'         => false,
        'EnableLiveTvManagement'     => false,
        'EnableContentDeletion'      => false,
        'EnablePublicSharing'        => false,
        'EnableCollectionManagement' => false,
        'EnableSubtitleManagement'   => false,
        'EnableLyricManagement'      => false,
        'SyncPlayAccess'             => 'None'
    ];
    return jellyfin_request("/Users/{$userId}/Policy", $policy, 'POST');
}

// --- NETTOYAGE DES COMPTES EXPIRÉS ---
$stmt = $db->query("SELECT jellyfin_id, username FROM users_expiration WHERE expire_at IS NOT NULL AND expire_at < DATETIME('now')");
$expiredUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($expiredUsers as $user) {
    jellyfin_request("/Users/{$user['jellyfin_id']}", [], 'DELETE');
    $delStmt = $db->prepare("DELETE FROM users_expiration WHERE jellyfin_id = ?");
    $delStmt->execute([$user['jellyfin_id']]);
}

// --- ENDPOINT PUBLIC POUR LE TEMPS RESTANT (utilisé par le JS Injector) ---
if ($action === 'get_expiration') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $username = trim($_GET['username'] ?? '');
    if (empty($username)) {
        echo json_encode(['error' => 'username required']);
        exit;
    }

    $stmt = $db->prepare("SELECT expire_at FROM users_expiration WHERE username = ? COLLATE NOCASE");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['expire_at'])) {
        echo json_encode(['days' => null, 'text' => 'Illimité']);
        exit;
    }

    $expireTime = strtotime($row['expire_at']);
    $diffDays = floor(($expireTime - time()) / 86400);

    if ($diffDays < 0) {
        $text = 'Expiré';
    } elseif ($diffDays === 0) {
        $text = 'Expire aujourd\'hui';
    } else {
        $text = $diffDays . ' jour' . ($diffDays > 1 ? 's' : '') . ' restant' . ($diffDays > 1 ? 's' : '');
    }

    echo json_encode([
        'days' => $diffDays,
        'text' => $text,
        'expire_at' => $row['expire_at']
    ]);
    exit;
}

// --- LOGIQUE ADMIN ---
if ($action === 'admin') {
    $authenticated = false;
    $adminCookieValid = false;

    if (isset($_COOKIE['admin_auth'])) {
        $cookieParts = explode('.', $_COOKIE['admin_auth'], 2);
        if (count($cookieParts) === 2) {
            [$cookieValue, $cookieSignature] = $cookieParts;
            $expectedSignature = hash_hmac('sha256', $cookieValue, ADMIN_COOKIE_SECRET);
            $adminCookieValid = hash_equals($expectedSignature, $cookieSignature)
                && $cookieValue === 'authenticated';
        }
    }

    if (
        (isset($_POST['admin_pass']) && password_verify($_POST['admin_pass'], ADMIN_PASSWORD_HASH))
        || $adminCookieValid
    ) {
        $authenticated = true;
        $cookieValue = 'authenticated';
        $cookieSignature = hash_hmac('sha256', $cookieValue, ADMIN_COOKIE_SECRET);
        setcookie('admin_auth', $cookieValue . '.' . $cookieSignature, [
            'expires'  => time() + 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ]);

        // Prolongation +30 jours
        if (isset($_POST['extend_user_id'])) {
            $userId = $_POST['extend_user_id'];
            $stmt = $db->prepare("UPDATE users_expiration SET expire_at = DATETIME(COALESCE(expire_at, DATETIME('now')), '+30 days') WHERE jellyfin_id = ?");
            $stmt->execute([$userId]);
            $message = "Accès prolongé de 30 jours.";
        }

        // Transformer en accès limité ou illimité
        if (isset($_POST['set_limited_user_id'])) {
            $userId = $_POST['set_limited_user_id'];
            $days   = intval($_POST['set_days'] ?? 30);

            $check = $db->prepare("SELECT username FROM users_expiration WHERE jellyfin_id = ?");
            $check->execute([$userId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($days <= 0) {
                // Passage en illimité
                if ($existing) {
                    $stmt = $db->prepare("UPDATE users_expiration SET expire_at = NULL WHERE jellyfin_id = ?");
                    $stmt->execute([$userId]);
                } else {
                    $usersListTemp = jellyfin_request('/Users', [], 'GET')['data'] ?? [];
                    $username = 'Inconnu';
                    foreach ($usersListTemp as $u) {
                        if (($u['Id'] ?? $u['id'] ?? '') === $userId) {
                            $username = $u['Name'] ?? $u['name'] ?? 'Inconnu';
                            break;
                        }
                    }
                    $stmt = $db->prepare("INSERT INTO users_expiration (jellyfin_id, username, expire_at) VALUES (?, ?, NULL)");
                    $stmt->execute([$userId, $username]);
                }
                $message = "Compte transformé en accès illimité.";
            } else {
                // Passage en accès limité
                if ($existing) {
                    $stmt = $db->prepare("UPDATE users_expiration SET expire_at = DATETIME('now', '+' || ? || ' days') WHERE jellyfin_id = ?");
                    $stmt->execute([$days, $userId]);
                } else {
                    $usersListTemp = jellyfin_request('/Users', [], 'GET')['data'] ?? [];
                    $username = 'Inconnu';
                    foreach ($usersListTemp as $u) {
                        if (($u['Id'] ?? $u['id'] ?? '') === $userId) {
                            $username = $u['Name'] ?? $u['name'] ?? 'Inconnu';
                            break;
                        }
                    }
                    $stmt = $db->prepare("INSERT INTO users_expiration (jellyfin_id, username, expire_at) VALUES (?, ?, DATETIME('now', '+' || ? || ' days'))");
                    $stmt->execute([$userId, $username, $days]);
                }
                $message = "Compte transformé en accès limité de {$days} jours.";
            }
        }

        // Suppression manuelle
        if (isset($_POST['delete_user_id'])) {
            $userId = $_POST['delete_user_id'];
            jellyfin_request("/Users/{$userId}", [], 'DELETE');
            $stmt = $db->prepare("DELETE FROM users_expiration WHERE jellyfin_id = ?");
            $stmt->execute([$userId]);
            $message = "Utilisateur supprimé.";
        }

        // Générer une invitation
        if (isset($_POST['generate_invite'])) {
            $token = bin2hex(random_bytes(16));
            $days  = intval($_POST['duration_days']);
            $stmt = $db->prepare("INSERT INTO invites (token, duration_days) VALUES (?, ?)");
            $stmt->execute([$token, $days]);
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $inviteUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?token=" . $token;
            $message   = "Lien créé : <br><input type='text' value='{$inviteUrl}' style='width:100%' readonly onclick='this.select()'>";
        }

        $stmt = $db->query("SELECT * FROM users_expiration");
        $expirations = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $usersList = jellyfin_request('/Users', [], 'GET')['data'] ?? [];
    }
}

// --- LOGIQUE INSCRIPTION ---
if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM invites WHERE token = ? AND used = 0");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite) {
        $message = "Lien d'invitation invalide ou expiré.";
    } elseif (empty($username) || empty($password)) {
        $message = "Veuillez remplir tous les champs.";
    } else {
        $res = jellyfin_request('/Users/New', [
            'Name'     => $username,
            'Password' => $password
        ]);

        if ($res['code'] === 200 && isset($res['data']['Id'])) {
            $userId = $res['data']['Id'];
            apply_user_restrictions($userId);

            $expireAt = null;
            if ($invite['duration_days'] > 0) {
                $expireAt = date('Y-m-d H:i:s', strtotime("+{$invite['duration_days']} days"));
            }
            $stmt = $db->prepare("INSERT INTO users_expiration (jellyfin_id, username, expire_at) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $username, $expireAt]);
            $stmt = $db->prepare("UPDATE invites SET used = 1 WHERE token = ?");
            $stmt->execute([$token]);
            $message = "Compte créé avec succès !";
        } else {
            // Fallback ancienne méthode
            $res = jellyfin_request('/Users/New', ['Name' => $username]);
            if ($res['code'] === 200 && isset($res['data']['Id'])) {
                $userId = $res['data']['Id'];
                jellyfin_request("/Users/{$userId}/Password", [
                    'Id'        => $userId,
                    'CurrentPw' => '',
                    'NewPw'     => $password
                ]);
                apply_user_restrictions($userId);

                $expireAt = null;
                if ($invite['duration_days'] > 0) {
                    $expireAt = date('Y-m-d H:i:s', strtotime("+{$invite['duration_days']} days"));
                }
                $stmt = $db->prepare("INSERT INTO users_expiration (jellyfin_id, username, expire_at) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $username, $expireAt]);
                $stmt = $db->prepare("UPDATE invites SET used = 1 WHERE token = ?");
                $stmt->execute([$token]);
                $message = "Compte créé avec succès !";
            } else {
                $errorMsg = $res['data']['Message'] ?? ($res['error'] ?? 'Erreur inconnue');
                $message = "Erreur lors de la création du compte (HTTP {$res['code']}) : " . htmlspecialchars($errorMsg);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Jellyfin</title>
    <style>
        body { font-family: Arial, sans-serif; background: #202020; color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #2d2d2d; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); width: 100%; max-width: 620px; }
        h2, h3 { color: #00a4dc; margin-top: 0; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 10px; margin: 8px 0; border-radius: 4px; border: 1px solid #444; background: #1a1a1a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #00a4dc; border: none; color: #fff; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 5px; }
        button:hover { background: #0085b3; }
        .btn-green { background: #28a745; margin: 0 3px; padding: 4px 8px; font-size: 12px; width: auto; }
        .btn-orange { background: #ff9800; margin: 0 3px; padding: 4px 8px; font-size: 12px; width: auto; }
        .btn-red { background: #dc3545; margin: 0; padding: 4px 8px; font-size: 12px; width: auto; }
        .message { background: #333; padding: 10px; border-left: 4px solid #00a4dc; margin-bottom: 15px; word-break: break-all; }
        .user-item { display: flex; justify-content: space-between; align-items: center; background: #1a1a1a; padding: 8px 12px; border-radius: 4px; margin-bottom: 8px; }
        .user-info small { color: #aaa; display: block; }
        .expired { color: #ff6b6b !important; }
        .warning { color: #ffb347 !important; }
    </style>
</head>
<body>
<div class="card">
    <?php if ($action === 'admin'): ?>
        <h2>Administration</h2>
        <?php if (!empty($message)) echo "<div class='message'>{$message}</div>"; ?>

        <?php if (!$authenticated): ?>
            <form method="POST">
                <input type="password" name="admin_pass" placeholder="Mot de passe Administrateur" required>
                <button type="submit">Se connecter</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="generate_invite" value="1">
                <label>Durée d'accès du compte :</label>
                <select name="duration_days">
                    <option value="7">7 jours</option>
                    <option value="30" selected>30 jours</option>
                    <option value="90">90 jours</option>
                    <option value="180">180 jours</option>
                    <option value="365">365 jours</option>
                    <option value="0">Illimité</option>
                </select>
                <button type="submit">Générer une invitation</button>
            </form>

            <h3 style="margin-top: 25px; border-top: 1px solid #444; padding-top: 15px;">Gestion des comptes</h3>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($usersList as $user):
                    $uId = $user['Id'] ?? $user['id'] ?? null;
                    if (!$uId) continue;

                    $expRaw = $expirations[$uId]['expire_at'] ?? null;
                    $expDisplay = 'Illimité';
                    $extraClass = '';

                    if ($expRaw) {
                        $expireTime = strtotime($expRaw);
                        $now = time();
                        $diffDays = floor(($expireTime - $now) / 86400);

                        if ($diffDays < 0) {
                            $expDisplay = 'Expiré';
                            $extraClass = 'expired';
                        } elseif ($diffDays === 0) {
                            $expDisplay = 'Expire aujourd\'hui';
                            $extraClass = 'warning';
                        } elseif ($diffDays <= 7) {
                            $expDisplay = "Expire dans {$diffDays} jour" . ($diffDays > 1 ? 's' : '');
                            $extraClass = 'warning';
                        } else {
                            $expDisplay = "Expire le " . date('d/m/Y', $expireTime) . " ({$diffDays}j)";
                        }
                    }
                ?>
                    <div class="user-item">
                        <div class="user-info">
                            <strong><?= htmlspecialchars($user['Name'] ?? $user['name'] ?? 'Inconnu') ?></strong>
                            <small class="<?= $extraClass ?>">Expire : <?= htmlspecialchars($expDisplay) ?></small>
                        </div>
                        <div style="display:flex; align-items:center;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="extend_user_id" value="<?= htmlspecialchars($uId) ?>">
                                <button type="submit" class="btn-green" title="Ajouter 30 jours">+30j</button>
                            </form>

                            <form method="POST" style="margin:0; display:flex; align-items:center;">
                                <input type="hidden" name="set_limited_user_id" value="<?= htmlspecialchars($uId) ?>">
                                <select name="set_days" style="width:auto; padding:3px 6px; margin:0 4px 0 0; font-size:12px;">
                                    <option value="7">7j</option>
                                    <option value="30" selected>30j</option>
                                    <option value="90">90j</option>
                                    <option value="180">180j</option>
                                    <option value="365">365j</option>
                                    <option value="0">Illimité</option>
                                </select>
                                <button type="submit" class="btn-orange" title="Appliquer la durée">→</button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Supprimer définitivement cet utilisateur ?');" style="margin:0;">
                                <input type="hidden" name="delete_user_id" value="<?= htmlspecialchars($uId) ?>">
                                <button type="submit" class="btn-red">X</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <h2>Inscription Jellyfin</h2>
        <?php if (!empty($message)) echo "<div class='message'>{$message}</div>"; ?>

        <?php
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
        if ($token && empty($res['code'] ?? null)):
        ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="text" name="username" placeholder="Nom d'utilisateur" required>
                <input type="password" name="password" placeholder="Mot de passe" required>
                <button type="submit">Créer mon compte</button>
            </form>
        <?php elseif (empty($message)): ?>
            <p>Un jeton d'invitation valide est requis pour accéder à cette page.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
