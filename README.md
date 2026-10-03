# Jellyfin Invite Manager

Petit script PHP autonome pour gérer les invitations et les comptes temporaires sur une instance **Jellyfin**.

## Fonctionnalités

- Génération de liens d’invitation avec durée configurable (7 / 30 / 90 / 180 / 365 jours ou illimité)
- Création automatique du compte Jellyfin + application de restrictions (dossiers, sessions, etc.)
- Gestion des dates d’expiration (prolongation, passage en illimité, suppression)
- Nettoyage automatique des comptes expirés
- Endpoint JSON public (`?action=get_expiration&username=...`) pour afficher le temps restant côté client
- Interface d’administration protégée par mot de passe + cookie signé

## Prérequis

- PHP 8+ avec les extensions `curl`, `pdo_sqlite` et `json`
- Une instance Jellyfin accessible 
- Une clé API Jellyfin (Dashboard → API Keys)

## Installation

1. Clone le dépôt ou copie `index.php` dans un dossier servi par ton serveur web (Apache, Nginx, Caddy…).

2. Édite le fichier index.php :

```php
define('JELLYFIN_URL', 'http://jellyfin:8096');          // URL de ton Jellyfin
define('JELLYFIN_API_KEY', 'TA_CLE_API');               // Clé API
define('ADMIN_PASSWORD_HASH', 'HASH_GENERE');           // voir ci-dessous
define('ADMIN_COOKIE_SECRET', 'SECRET_ALEATOIRE_LONG'); // openssl rand -base64 32
```

3. Génère le hash du mot de passe admin :

```bash
php -r "echo password_hash('TON_MOT_DE_PASSE', PASSWORD_DEFAULT);"
```
Ou en ligne
https://bcrypt.online


4. Accède à :
   - `/index.php?action=admin` → panneau d’administration
   - `/index.php?token=XXXX` → page d’inscription (lien généré dans l’admin)

## Base de données

Une base SQLite `invites.sqlite` est créée automatiquement à côté du script.  



## Personnalisation des restrictions

La fonction `apply_user_restrictions()` définit les droits du nouvel utilisateur.  
Tu peux modifier :
- les dossiers autorisés (actuellement tous sauf « Jeux »)
- le nombre de sessions simultanées
- le téléchargement, le Live TV, SyncPlay, etc.

## Sécurité

- Ne communique jamais ta vraie clé API, ton hash de mot de passe ou ton secret cookie.
- Préfère HTTPS + reverse-proxy.
- Le cookie d’admin est signé avec HMAC-SHA256 et marqué `HttpOnly` + `SameSite=Lax`.

## Licence

Libre d’utilisation. Fais-en ce que tu veux.
