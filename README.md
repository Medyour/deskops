# DeskOps

[![CI](https://github.com/Medyour/deskops/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Medyour/deskops/actions/workflows/ci.yml)

DeskOps est un mini-centre de support client pour une petite équipe. Ce projet vitrine démontrera des accès par rôle, un workflow de tickets, des règles métier testées, une chronologie d’activité et un tableau de bord.

## État actuel — fondations, CI et cœur métier des tickets

Les fondations sont prêtes : vitrine responsive, connexion/déconnexion, « se souvenir de moi », récupération et confirmation du mot de passe, rôles **administrateur** et **agent**, espaces protégés et comptes de démonstration locaux.

Le cœur métier des tickets est disponible : création, assignation/réassignation, transitions contrôlées, permissions, conflits de version et historique transactionnel immuable. Les commentaires disposent d’une action interne et servent la fixture de démonstration. Les pages et formulaires complets des tickets, l’édition des champs/priorités, les filtres et les indicateurs restent à construire. Aucune inscription publique.

Le contrat complet se trouve dans [docs/MVP.md](docs/MVP.md), les choix de structure dans [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Stack

- PHP **8.3+** (CI sous 8.3), Laravel **13**, Fortify ; Composer **2.10.3** (`.composer-version`).
- SQLite pour les données, sessions et cache ; aucune infrastructure additionnelle.
- Blade, Tailwind CSS **4**, Vite **8**, fontes système.
- Node **24.19.0** (`.node-version`, minimum compatible **22.12**) et npm **11.17.0** (`packageManager` dans `package.json`) ; dépendances exactes dans les lockfiles.
- Pest **4** / PHPUnit, Laravel Pint et Prettier.
- Laravel Boost en dépendance de développement, avec instructions du projet dans `AGENTS.md`.

Les vues ne dépendent pas de JavaScript. Vue sera réservé à de futurs îlots utiles ; aucune SPA ni dépendance Vue inutile dans cette étape. Laravel 13 accepte PHP 8.3 ([documentation officielle](https://laravel.com/docs/13.x/releases)). Fortify gère le socle d’authentification ([documentation](https://laravel.com/docs/13.x/fortify)).

## Installation locale

Prérequis : PHP 8.3 avec PDO SQLite, sqlite3, mbstring, openssl, fileinfo, curl, dom, xml et zip ; Composer 2.10.3, Node/npm aux versions indiquées ci-dessus et Git.

Depuis la racine du projet :

```sh
composer run setup
php artisan deskops:demo
```

`setup` installe les dépendances verrouillées, crée `.env` s’il manque, génère la clé applicative seulement si elle manque, crée le fichier SQLite, applique les migrations puis construit les assets. Il ne supprime aucune base et ne renouvelle aucune clé existante.

`deskops:demo` crée/met à jour les trois identités de démonstration, initialise **12 tickets, 24 commentaires et 60 activités** sur une base de tickets neuve, affiche de nouveaux mots de passe aléatoires et révoque leurs anciennes sessions/liens de récupération. Relancer cette commande renouvelle les accès et conserve les mutations de tickets, les ajouts et l’historique. Les mots de passe ne sont pas enregistrés en clair dans un fichier et ne sont pas fournis dans le dépôt.

| Compte                  | Rôle           |
| ----------------------- | -------------- |
| `admin@deskops.example` | Administrateur |
| `alex@deskops.example`  | Agent          |
| `sam@deskops.example`   | Agent          |

`php artisan db:seed` est idempotent et conserve les mots de passe existants ; pour obtenir des identifiants utilisables, lancer la commande de démonstration. Ces données n’utilisent aucune identité réelle. Le seed est réservé à local/testing ; la commande est réservée à local.

Si des tickets étrangers à la fixture existent, si la fixture est partielle ou si ses identifiants ont déjà été utilisés, le seed refuse l’initialisation sans écraser de données. La commande n’effectue alors aucune rotation des accès. Aucun reset automatique n’est prévu. Les dates métier sont fixes au 15 janvier 2026 en UTC ; elles ne sont pas recalées sur la date du jour.

### Contrat des mutations

Les actions de `app/Actions/Tickets` centralisent validation, autorisation, transaction et activité. La référence `DS-0001` dérive de la clé primaire SQLite et reste unique au-delà de quatre chiffres. Les mutations transmettent la version observée du ticket ; une version obsolète ou un verrou SQLite entraîne un conflit HTTP 409, sans écriture partielle. Les répétitions autorisées à jour sont sans effet.

Trois routes web protégées par session et CSRF préparent les futurs formulaires : `POST /tickets`, `PATCH /tickets/{ticket}/assignment` et `PATCH /tickets/{ticket}/status`. Les contrôleurs délèguent aux actions et redirigent vers l’espace équipe. Les commentaires restent accessibles par action interne à ce stade. Le graphe, les motifs obligatoires et les permissions détaillées sont définis dans [le contrat du MVP](docs/MVP.md).

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

## Qualité et CI GitHub Actions

Avant chaque commit :

```sh
composer run check
```

Cette commande exécute, dans l’ordre : validation stricte de Composer, vérification des exigences de la plateforme, audit Composer (y compris les dépendances de développement), Pint en vérification, suite Laravel, audit npm, Prettier en vérification, le script de tests JavaScript s’il est défini, puis le build Vite. Elle s’arrête si un contrôle échoue. Aucun audit ou test présent n’est rendu facultatif.

Le workflow [`.github/workflows/ci.yml`](.github/workflows/ci.yml) exécute l’installation `composer run setup` sur une base SQLite neuve, puis exactement `composer run check`. Il tourne sous Ubuntu 24.04 et PHP 8.3, vérifie `pdo_sqlite`/`sqlite3` et les versions Composer, Node et npm, puis installe les dépendances depuis les deux lockfiles. Il se déclenche sur les pushes vers `main` et `agent/**`, sur les PR vers `main` (brouillons compris), et manuellement.

Les actions sont épinglées par SHA. Le jeton automatique GitHub est limité à la lecture du dépôt, sans persistance des identifiants Git. Seuls les caches de téléchargement Composer/npm sont réutilisés ; ni `vendor`, ni `node_modules`, ni `.env`, ni base SQLite ne sont mis en cache. Aucun secret GitHub supplémentaire ni étape de déploiement ne sont nécessaires.

Le badge ci-dessus vise `main`. Chaque PR dispose de ses propres contrôles. Pour ce dépôt privé, le badge est consultable par les personnes autorisées.

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

Les tests Feature utilisent SQLite en mémoire, sans modifier la base locale ; le test de concurrence utilise deux connexions à un fichier SQLite temporaire supprimé après le test. Ils couvrent l’authentification et les rôles, les transitions autorisées/interdites, les entrées invalides, l’assignation, les versions obsolètes, les verrous SQLite, le rollback de l’historique, les contraintes SQL et l’idempotence de la fixture. Les tests Unit couvrent le graphe complet des statuts. Aucune suite JavaScript ni script `test` npm n’existent encore. `npm run test --if-present` exécute automatiquement ce script lorsqu’il sera ajouté, sans ignorer ses échecs.

## Fichiers à connaître

| Emplacement                                                  | Responsabilité                                               |
| ------------------------------------------------------------ | ------------------------------------------------------------ |
| `routes/web.php`                                             | Vitrine, espaces protégés et mutations des tickets           |
| `app/Enums/UserRole.php`                                     | Rôles typés                                                  |
| `app/Models/User.php`                                        | Utilisateur et casts                                         |
| `app/Providers/FortifyServiceProvider.php`                   | Authentification et vues                                     |
| `app/Providers/AppServiceProvider.php`                       | Gate et politique de mot de passe                            |
| `config/fortify.php`                                         | Fonctionnalités d’authentification activées                  |
| `database/migrations`                                        | Schéma, clés étrangères, index et contraintes SQLite         |
| `app/Models/Ticket.php`, `Comment.php`, `TicketActivity.php` | Relations et casts métier                                    |
| `app/Enums/TicketStatus.php`, `TicketPriority.php`           | Statuts, graphe et priorités typés                           |
| `app/Actions/Tickets`                                        | Mutations transactionnelles et contrôle de version           |
| `app/Policies/TicketPolicy.php`, `app/Http/Requests`         | Autorisations et validation Laravel                          |
| `database/seeders/DemoUserSeeder.php`                        | Identités déterministes                                      |
| `database/seeders/DemoTicketSeeder.php`                      | Fixture déterministe, sans écrasement                        |
| `app/Console/Commands/PrepareDemoAccounts.php`               | Préparation des accès locaux                                 |
| `resources/views`                                            | Vitrine, formulaires et espaces équipe                       |
| `tests/Feature`, `tests/Unit`                                | Scénarios HTTP, sécurité, métier, intégrité et démonstration |
| `composer.json`, `package.json`                              | Scripts de développement et de qualité                       |
| `.github/workflows/ci.yml`                                   | CI GitHub Actions                                            |
| `.node-version`, `.composer-version`                         | Versions des outils de développement et CI                   |

## Feuille de route

1. **Fondations — livré** : environnement, authentification, rôles, vitrine, documentation, outillage et premier commit local.
2. **Dépôt privé et CI — livré** : publication sur `Medyour/deskops`, installation reproductible, audits bloquants et contrôles GitHub Actions.
3. **Cœur métier des tickets — implémenté sur la branche de travail** : migrations, enums, policy, validations, assignation, transitions, historique immuable, commentaires internes et fixture de 12 tickets ; tests des refus et de la concurrence.
4. **Parcours des tickets** : liste, détail, formulaires, édition des champs et priorités, présentation des commentaires et de la chronologie ; messages de validation et de conflit.
5. **Recherche et pilotage** : filtres persistants, pagination, indicateurs à horloge testable et éventuel îlot Vue justifié.
6. **Finition de la vitrine** : parcours de démonstration, vérifications clavier/mobile et documentation finale.

Le dépôt GitHub est privé : [`Medyour/deskops`](https://github.com/Medyour/deskops). La CI ne publie aucun site ni artefact de déploiement. Aucun hébergement ou domaine n’est configuré. Le contrat détaillé du MVP fait foi pour les prochaines étapes métier.
