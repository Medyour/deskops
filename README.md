# DeskOps

DeskOps est un mini-centre de support client pour une petite équipe. Ce projet vitrine démontrera des accès par rôle, un workflow de tickets, des règles métier testées, une chronologie d’activité et un tableau de bord.

## État actuel — étape 1

Les fondations sont prêtes : vitrine responsive, connexion/déconnexion, « se souvenir de moi », récupération et confirmation du mot de passe, rôles **administrateur** et **agent**, espaces protégés et comptes de démonstration locaux.

Les tickets, commentaires, filtres et indicateurs métier sont **cadrés mais pas développés**. L’espace équipe présente clairement les prochaines fonctionnalités. Aucune inscription publique.

Le contrat complet se trouve dans [docs/MVP.md](docs/MVP.md), les choix de structure dans [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Stack

- PHP **8.3+**, Laravel **13**, Fortify ; Composer 2.
- SQLite pour les données, sessions et cache ; aucune infrastructure additionnelle.
- Blade, Tailwind CSS **4**, Vite **8**, fontes système.
- Node **24 LTS** conseillé (minimum **22.12**), npm ; versions exactes dans les lockfiles.
- Pest **4** / PHPUnit, Laravel Pint et Prettier.
- Laravel Boost en dépendance de développement, avec instructions du projet dans `AGENTS.md`.

Les vues ne dépendent pas de JavaScript. Vue sera réservé à de futurs îlots utiles ; aucune SPA ni dépendance Vue inutile dans cette étape. Laravel 13 accepte PHP 8.3 ([documentation officielle](https://laravel.com/docs/13.x/releases)). Fortify gère le socle d’authentification ([documentation](https://laravel.com/docs/13.x/fortify)).

## Installation locale

Prérequis : PHP 8.3 avec PDO SQLite, sqlite3, mbstring, openssl, fileinfo, curl, dom, xml et zip ; Composer 2, Node/npm et Git.

Depuis la racine du projet :

```sh
composer run setup
php artisan deskops:demo
```

`setup` installe les dépendances verrouillées, crée `.env` s’il manque, génère la clé applicative seulement si elle manque, crée le fichier SQLite, applique les migrations puis construit les assets. Il ne supprime aucune base et ne renouvelle aucune clé existante.

`deskops:demo` crée/met à jour les trois identités de démonstration, affiche de nouveaux mots de passe aléatoires et révoque leurs anciennes sessions/liens de récupération. Relancer cette commande renouvelle les accès. Les mots de passe ne sont pas enregistrés en clair dans un fichier et ne sont pas fournis dans le dépôt.

| Compte                  | Rôle           |
| ----------------------- | -------------- |
| `admin@deskops.example` | Administrateur |
| `alex@deskops.example`  | Agent          |
| `sam@deskops.example`   | Agent          |

`php artisan db:seed` est idempotent et conserve les mots de passe existants ; pour obtenir des identifiants utilisables, lancer la commande de démonstration. Ces données n’utilisent aucune identité réelle. Le seed est réservé à local/testing ; la commande est réservée à local.

### Ouvrir l’application

Avec **Laravel Herd** déjà installé : ouvrir l’URL locale indiquée par `herd sites` pour ce dossier. Adapter `APP_URL` dans `.env` à cette URL. Aucun domaine, certificat ou hébergement n’est créé par ce projet. Pour recharger les styles en développement :

```sh
npm run dev
```

Sans Herd :

```sh
composer run dev
```

L’application est alors accessible sur `http://localhost:8000`. Le processus lance Laravel et Vite, sans file d’attente additionnelle.

### Installation manuelle équivalente

```sh
composer install
```

Copier `.env.example` vers `.env` (`Copy-Item .env.example .env` en PowerShell ou `cp .env.example .env`), puis :

```sh
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan deskops:prepare --no-interaction
php artisan migrate --no-interaction
npm ci --ignore-scripts
npm run build
php artisan deskops:demo
```

La configuration par défaut utilise `database/database.sqlite`. Aucun identifiant de base n’est requis. Les assets construits permettent aussi de consulter l’application sans serveur Vite.

### Récupération du mot de passe en local

Le formulaire « Mot de passe oublié » utilise Fortify et le broker Laravel. Avec `MAIL_MAILER=log`, le lien est écrit dans `storage/logs/laravel.log` ; aucun e-mail réel n’est envoyé. Ce journal contient des liens temporaires et reste ignoré par Git. Le nouveau mot de passe exige au moins 12 caractères, une majuscule, une minuscule et un chiffre.

La clé applicative, les hashes et les sessions nécessaires existent exclusivement dans les fichiers locaux ignorés. `.env.example` contient une clé vide et aucun secret. Aucun service externe n’est configuré.

## Qualité et CI locale

Avant chaque commit :

```sh
composer run check
```

Cette commande exécute, dans l’ordre : validation stricte de Composer, contrôle du format PHP, suite Laravel, contrôle Prettier et build Vite. Elle s’arrête si un contrôle échoue. Aucun service de CI distant n’est configuré.

Commandes ciblées :

```sh
composer run format
composer run lint
composer run test
npm run format
npm run lint
npm run build
composer audit
npm audit
```

Les tests Feature utilisent SQLite en mémoire, sans modifier la base locale. Ils couvrent les accès, les deux rôles, les refus de privilèges, CSRF, la limitation de connexion, la rotation de session, la récupération du mot de passe et les comptes de démo. Aucune logique JavaScript applicative ne justifie encore une suite JS ; elle sera ajoutée avec le premier îlot interactif.

## Fichiers à connaître

| Emplacement                                    | Responsabilité                              |
| ---------------------------------------------- | ------------------------------------------- |
| `routes/web.php`                               | Vitrine et espaces protégés                 |
| `app/Enums/UserRole.php`                       | Rôles typés                                 |
| `app/Models/User.php`                          | Utilisateur et casts                        |
| `app/Providers/FortifyServiceProvider.php`     | Authentification et vues                    |
| `app/Providers/AppServiceProvider.php`         | Gate et politique de mot de passe           |
| `config/fortify.php`                           | Fonctionnalités d’authentification activées |
| `database/migrations`                          | Schéma initial et contrainte de rôle        |
| `database/seeders/DemoUserSeeder.php`          | Identités déterministes                     |
| `app/Console/Commands/PrepareDemoAccounts.php` | Préparation des accès locaux                |
| `resources/views`                              | Vitrine, formulaires et espaces équipe      |
| `tests/Feature`                                | Scénarios HTTP, sécurité et démonstration   |
| `composer.json`, `package.json`                | Scripts de développement et de qualité      |

## Feuille de route

1. **Fondations — livré** : environnement, authentification, rôles, vitrine, documentation, outillage et premier commit local.
2. **Tickets et workflow** : migrations, enums, policies, validations, liste/détail/création, priorités, assignation et transitions ; tests des refus et de la concurrence.
3. **Collaboration et audit** : commentaires internes immuables, historique transactionnel et jeu de 12 tickets déterministes.
4. **Recherche et pilotage** : filtres persistants, pagination, indicateurs à horloge testable et éventuel îlot Vue justifié.
5. **Finition de la vitrine** : parcours de démonstration, vérifications clavier/mobile et documentation finale.

Aucun dépôt GitHub, hébergement ou domaine n’est créé dans cette étape. Le contrat détaillé du MVP fait foi pour les étapes suivantes.
