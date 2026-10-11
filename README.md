# DeskOps

[![CI](https://github.com/Medyour/deskops/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Medyour/deskops/actions/workflows/ci.yml)

DeskOps est un mini-centre de support client pour une petite équipe. Ce projet vitrine démontrera des accès par rôle, un workflow de tickets, des règles métier testées, une chronologie d’activité et un tableau de bord.

## État actuel — espace tickets et tableau de bord

Les fondations sont prêtes : vitrine responsive, connexion/déconnexion, « se souvenir de moi », récupération et confirmation du mot de passe, rôles **administrateur** et **agent**, espaces protégés et comptes de démonstration locaux.

L’espace tickets est disponible : liste paginée, recherche et filtres combinables dans l’URL, création, détail, édition des informations et de la priorité, assignation/réassignation, transitions autorisées, commentaires internes et historique immuable. Les formulaires réutilisent les actions et policies du cœur métier. Un conflit 409 conserve la saisie à l’écran, présente les valeurs actuelles et demande une reprise explicite. Aucune inscription publique.

Le tableau de bord authentifié présente les volumes, répartitions par statut/priorité, charge active des agents, file À traiter et cinq tickets/activités récents. Les agents peuvent basculer entre Équipe et Mes tickets. Blade et Tailwind suffisent ; aucun graphique externe ni JavaScript métier n’est ajouté.

Le contrat complet se trouve dans [docs/MVP.md](docs/MVP.md), les choix de structure dans [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Stack

- PHP **8.3+** (CI sous 8.3), Laravel **13**, Fortify ; Composer **2.10.3** (`.composer-version`).
- SQLite pour le développement local rapide ; PostgreSQL **18** pour les environnements serveur. La future démonstration DigitalOcean utilisera une base de développement, distincte d’une base gérée de production. Sessions et cache utilisent le moteur choisi.
- Blade, Tailwind CSS **4**, Vite **8**, fontes système.
- Node **24.19.0** (`.node-version`, minimum compatible **22.12**) et npm **11.17.0** (`packageManager` dans `package.json`) ; dépendances exactes dans les lockfiles.
- Pest **4** / PHPUnit, Laravel Pint et Prettier.
- Laravel Boost en dépendance de développement, avec instructions du projet dans `AGENTS.md`.

Les vues ne dépendent pas de JavaScript. Vue sera réservé à de futurs îlots utiles ; aucune SPA ni dépendance Vue inutile dans cette étape. Laravel 13 accepte PHP 8.3 ([documentation officielle](https://laravel.com/docs/13.x/releases)). Fortify gère le socle d’authentification ([documentation](https://laravel.com/docs/13.x/fortify)).

## Installation locale

Prérequis : PHP 8.3 avec PDO PostgreSQL (`pdo_pgsql`), PDO SQLite (`pdo_sqlite`), sqlite3, mbstring, openssl, fileinfo, curl, dom, xml et zip ; Composer 2.10.3, Node/npm aux versions indiquées ci-dessus et Git. Composer déclare et contrôle les deux pilotes ; il n’installe pas les extensions natives de PHP.

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

Les actions de `app/Actions/Tickets` centralisent validation, autorisation, transaction et activité. La référence `DS-0001` dérive de la clé primaire auto-incrémentée et reste unique au-delà de quatre chiffres, sur les deux moteurs. Des trous dans la séquence sont normaux, notamment après un rollback PostgreSQL. Les mutations transmettent la version observée du ticket ; une version obsolète ou un conflit de verrou entraîne un conflit HTTP 409, sans écriture partielle. Les répétitions autorisées à jour sont sans effet.

Les pages protégées sont `GET /tickets`, `/tickets/create`, `/tickets/{ticket}` et `/tickets/{ticket}/edit`. Les cinq mutations sous session/CSRF couvrent création, édition, assignation, statut et commentaire. Les contrôleurs délèguent aux actions et redirigent vers le détail. L’édition ajoute une activité par catégorie modifiée et n’incrémente la version qu’une fois. Le graphe, les motifs obligatoires et les permissions détaillées sont définis dans [le contrat du MVP](docs/MVP.md).

### Tableau de bord opérationnel

`GET /dashboard` est l’accueil après connexion. Les deux rôles voient la charge globale ; `?scope=mine` limite tous les résultats aux tickets actuellement assignés à l’agent connecté, activités comprises.

- **Actifs** : Nouveau, En cours ou En attente du client ; les tickets Résolu/Fermé sont exclus.
- **Non assignés** : actifs sans agent ; **priorités élevées** : actifs de priorité Haute ou Urgente ; **urgents actifs** : priorité Urgente uniquement.
- **À traiter** : union des actifs non assignés et des actifs de priorité Haute/Urgente, sans double comptage. Aperçu de cinq tickets : priorité décroissante, création la plus ancienne, identifiant croissant.
- **Terminés** : tous les tickets actuellement au statut Résolu ou Fermé, quelle que soit leur date de résolution.
- Les répartitions couvrent tous les statuts du périmètre. La charge par agent compte uniquement ses tickets actifs. Les créations et activités récentes sont triées par date puis identifiant décroissants et limitées à cinq.

Les liens ouvrent la liste avec les filtres correspondants (`queue`, statut, priorité, assignation), conservés dans la pagination et combinables avec la recherche. Les répartitions restent textuelles, les états vides explicites. Aucun SLA, échéance, durée moyenne ou classement de performance n’est calculé.

Avec la fixture intacte : **12 tickets, 9 actifs, 3 non assignés actifs, 3 priorités élevées actives, 1 urgent actif, 3 terminés, 5 dans la file À traiter** ; charge active Alex **3**, Sam **3**. Ces valeurs ne dépendent pas de la date de consultation ; les dates historiques de la démo restent fixes. Les définitions et résultats détaillés sont dans [docs/MVP.md](docs/MVP.md).

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

### Configuration PostgreSQL 18 au runtime

PostgreSQL 18 est le moteur serveur compatible ; aucune infrastructure n’est créée par ce dépôt. Fournir `DB_CONNECTION=pgsql` et `DATABASE_URL` dans l’environnement du processus. L’URL est la source de vérité, interprétée par Laravel (schémas `postgres` ou `postgresql`) sans la modifier ; `DB_URL` reste un alias de secours si `DATABASE_URL` est absent. Ne pas placer une URL réelle dans Git, une commande publiée ou les logs.

| Variable         | Attente                                                                           |
| ---------------- | --------------------------------------------------------------------------------- |
| `DB_CONNECTION`  | `pgsql` pour PostgreSQL, `sqlite` par défaut                                      |
| `DATABASE_URL`   | URL fournie au runtime : hôte, port, base et authentification si requise          |
| `DB_SSLMODE`     | Mode facultatif de repli, seulement si l’URL ne fournit pas `sslmode`             |
| `DB_SSLROOTCERT` | Autorité de confiance réelle ou magasin système explicitement configuré, hors Git |

Tous les paramètres de l’URL priment sur les valeurs de repli correspondantes, notamment `sslmode`, `sslrootcert`, `timezone` et `isolation_level`. UTC et `READ COMMITTED` restent les valeurs de repli prévues pour les dates et le contrôle de version ; elles ne réécrivent pas une URL fournie. L’utilisateur exécutant les migrations doit pouvoir créer les tables, index, fonctions et triggers dans son schéma. Le schéma par défaut est `public`.

Aucun mode TLS ni certificat racine n’est imposé par l’application. Le mode `require` chiffre la connexion ; `verify-full` ajoute la vérification de la chaîne de confiance et du nom du serveur. Pour une future base gérée, choisir explicitement `verify-full` seulement avec une autorité réelle fournie au runtime via `sslrootcert`/`DB_SSLROOTCERT`, ou un magasin système approprié explicitement sélectionné (`sslrootcert=system`, si libpq le prend en charge et si l’autorité du serveur y est reconnue). Aucun certificat fictif n’est fourni. Sans mode explicite, le comportement revient à libpq : ce n’est pas une garantie de chiffrement. [Référence TLS PostgreSQL](https://www.postgresql.org/docs/18/libpq-ssl.html).

`composer run setup` et `deskops:prepare` restent réservés à SQLite local. Pour PostgreSQL, sur une base déjà fournie et sans lancer de service système : installer les lockfiles avec `composer install --no-interaction --prefer-dist` et `npm ci --ignore-scripts`, fournir la configuration et la clé applicative hors Git, puis exécuter `php artisan migrate --force --no-interaction` et `npm run build`. Aucun seed de démonstration n’est autorisé en production.

### Future base de développement DigitalOcean

Pour le futur composant de base nommé `deskops-db`, l’application située dans la **même région et le même VPC** devra recevoir `DATABASE_URL` via la référence symbolique `${deskops-db.DATABASE_PRIVATE_URL}`. Cette variable de liaison est résolue par DigitalOcean au runtime ; elle ne doit pas être utilisée comme un nom d’hôte littéral ni remplacée par une URL réelle dans Git. La connexion privée exige que les deux composants appartiennent effectivement au même VPC. [Variables de liaison DigitalOcean](https://docs.digitalocean.com/products/app-platform/how-to/use-environment-variables/).

Conserver intégralement l’URL fournie par DigitalOcean et ses paramètres. Pour cette base de démonstration, le mode attendu est celui de l’URL, généralement `sslmode=require`, qui chiffre la connexion sans imposer une autorité racine absente. Ne pas remplacer ce mode par `verify-full` sans avoir configuré une confiance appropriée.

Cette future base est une **base de développement pour des données fictives**, sans fonctionnalité de sauvegarde intégrée ; elle ne représente pas une base de production sauvegardée. Une exploitation de production demanderait une base gérée et une politique de sauvegarde décidées séparément. [Limites des sauvegardes de développement](https://docs.digitalocean.com/support/how-do-i-back-up-my-dev-database-on-app-platform/). Cette étape documente uniquement le choix : aucun App Spec, composant, hébergement ou base DigitalOcean n’est créé.

### Valider les deux moteurs

La suite SQLite utilise une base en mémoire ; les tests de migrations et de concurrence créent des fichiers temporaires. Depuis un shell POSIX :

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DATABASE_URL= composer run check
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DATABASE_URL= php vendor/bin/pest --compact --order-by=random --random-order-seed=20261011
```

Pour PostgreSQL, fournir d’abord une `DATABASE_URL` vers une **base de test jetable**, dont le nom se termine par `_test`. La suite refuse une autre base ou un environnement autre que `testing`. Chaque test recrée le schéma : elle détruit donc les données de cette base. Les tests de concurrence et de rollback utilisent des schémas temporaires distincts, supprimés après le contrôle. Ne jamais utiliser une base contenant des données à conserver.

```sh
# DATABASE_URL est déjà fourni au processus, exclusivement vers la base jetable.
DB_CONNECTION=pgsql APP_ENV=testing php artisan migrate:fresh --force --no-interaction
DB_CONNECTION=pgsql APP_ENV=testing php artisan migrate:rollback --step=3 --force --no-interaction
DB_CONNECTION=pgsql APP_ENV=testing php artisan migrate --force --no-interaction
DB_CONNECTION=pgsql APP_ENV=testing composer run check
DB_CONNECTION=pgsql APP_ENV=testing php vendor/bin/pest --compact --order-by=random --random-order-seed=20261011
```

En PowerShell, définir les mêmes variables avec `$env:DB_CONNECTION = 'pgsql'` et `$env:APP_ENV = 'testing'`, puis exécuter les commandes sans leurs préfixes POSIX. La suite vérifie aussi l’installation vide, les rollbacks sans triggers/fonctions résiduels et deux appels à `deskops:demo` en environnement local simulé, sans exposer les accès générés.

Sur la machine ayant préparé cette compatibilité, les deux pilotes PHP sont disponibles mais aucun serveur PostgreSQL ni Docker ne l’est. Aucun service système n’a été installé : la validation PostgreSQL réelle est assurée par le service éphémère de la CI. SQLite reste immédiatement utilisable localement.

### Récupération du mot de passe en local

Le formulaire « Mot de passe oublié » utilise Fortify et le broker Laravel. Avec `MAIL_MAILER=log`, le lien est écrit dans `storage/logs/laravel.log` ; aucun e-mail réel n’est envoyé. Ce journal contient des liens temporaires et reste ignoré par Git. Le nouveau mot de passe exige au moins 12 caractères, une majuscule, une minuscule et un chiffre.

La clé applicative, les hashes et les sessions nécessaires existent exclusivement dans les fichiers locaux ignorés. `.env.example` contient une clé vide et aucun secret. Aucun service externe n’est configuré.

## Qualité et CI GitHub Actions

Avant chaque commit :

```sh
composer run check
```

Cette commande exécute, dans l’ordre : validation stricte de Composer, vérification des exigences de la plateforme, audit Composer (y compris les dépendances de développement), Pint en vérification, suite Laravel, audit npm, Prettier en vérification, le script de tests JavaScript s’il est défini, puis le build Vite. Elle s’arrête si un contrôle échoue. Aucun audit ou test présent n’est rendu facultatif.

Le workflow [`.github/workflows/ci.yml`](.github/workflows/ci.yml) conserve l’installation `composer run setup` sur une base SQLite neuve et ajoute une seconde exécution sur PostgreSQL 18. Les deux exécutent exactement `composer run check`, puis toute la suite dans un ordre aléatoire reproductible. Il tourne sous Ubuntu 24.04 et PHP 8.3, vérifie `pdo_pgsql`/`pdo_sqlite`/`sqlite3` et les versions Composer, Node et npm, puis installe les dépendances depuis les deux lockfiles. Il se déclenche sur les pushes vers `main` et `agent/**`, sur les PR vers `main` (brouillons compris), et manuellement.

PostgreSQL utilise l’image officielle `postgres:18`, épinglée par digest, sans volume persistant ni base externe. Un contrôle de santé TCP attend sa disponibilité ; une requête vérifie ensuite la version majeure 18 avant les migrations depuis une base vide. L’authentification `trust` sans mot de passe et `sslmode=disable` concernent exclusivement ce service jetable, dont le port est publié sur la boucle locale du runner. L’application respecte ce paramètre explicite de l’URL sans imposer un autre mode TLS. Les deux contrôles sont bloquants et indépendants.

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

La même suite Feature s’exécute sur les deux moteurs, sans modifier la base SQLite locale. PostgreSQL utilise `DatabaseMigrations` entre les tests, car ses séquences ne sont pas annulées avec les transactions ; SQLite conserve `RefreshDatabase`. Les tests couvrent les pages, filtres combinés, pagination, requêtes sans N+1, textes échappés, brouillons après 409 et édition atomique, ainsi que l’authentification, les rôles, les transitions, les entrées invalides, l’assignation, les versions obsolètes, les verrous, le rollback de l’historique, les contraintes SQL, la réversibilité des migrations et l’idempotence de la fixture. Les tests Unit couvrent le graphe complet des statuts. Aucune suite JavaScript ni script `test` npm n’existent encore. `npm run test --if-present` exécute automatiquement ce script lorsqu’il sera ajouté, sans ignorer ses échecs.

## Fichiers à connaître

| Emplacement                                                  | Responsabilité                                                  |
| ------------------------------------------------------------ | --------------------------------------------------------------- |
| `routes/web.php`                                             | Vitrine, espaces protégés et mutations des tickets              |
| `app/Enums/UserRole.php`                                     | Rôles typés                                                     |
| `app/Models/User.php`                                        | Utilisateur et casts                                            |
| `app/Providers/FortifyServiceProvider.php`                   | Authentification et vues                                        |
| `app/Providers/AppServiceProvider.php`                       | Gate et politique de mot de passe                               |
| `config/fortify.php`                                         | Fonctionnalités d’authentification activées                     |
| `database/migrations`                                        | Schéma, clés étrangères, index et protections SQLite/PostgreSQL |
| `app/Models/Ticket.php`, `Comment.php`, `TicketActivity.php` | Relations et casts métier                                       |
| `app/Enums/TicketStatus.php`, `TicketPriority.php`           | Statuts, graphe et priorités typés                              |
| `app/Actions/Tickets`                                        | Mutations transactionnelles et contrôle de version              |
| `app/Policies/TicketPolicy.php`, `app/Http/Requests`         | Autorisations et validation Laravel                             |
| `app/Actions/Tickets/OperationsDashboard.php`                | Agrégations et files du tableau de bord                         |
| `app/Enums/TicketQueue.php`, `Ticket::inQueue`               | Prédicats partagés des vues opérationnelles                     |
| `app/Http/Controllers/DashboardController.php`               | Accueil authentifié et périmètre validé                         |
| `database/seeders/DemoUserSeeder.php`                        | Identités déterministes                                         |
| `database/seeders/DemoTicketSeeder.php`                      | Fixture déterministe, sans écrasement                           |
| `app/Console/Commands/PrepareDemoAccounts.php`               | Préparation des accès locaux                                    |
| `resources/views`                                            | Vitrine, formulaires et espaces équipe                          |
| `tests/Feature`, `tests/Unit`                                | Scénarios HTTP, sécurité, métier, intégrité et démonstration    |
| `composer.json`, `package.json`                              | Scripts de développement et de qualité                          |
| `.github/workflows/ci.yml`                                   | CI GitHub Actions                                               |
| `.node-version`, `.composer-version`                         | Versions des outils de développement et CI                      |

## Feuille de route

1. **Fondations — livré** : environnement, authentification, rôles, vitrine, documentation, outillage et premier commit local.
2. **Dépôt privé et CI — livré** : publication sur `Medyour/deskops`, installation reproductible, audits bloquants et contrôles GitHub Actions.
3. **Cœur métier des tickets — livré** : migrations, enums, policy, validations, assignation, transitions, historique immuable, commentaires internes et fixture de 12 tickets ; tests des refus et de la concurrence.
4. **Parcours des tickets — livré** : liste, détail, création, édition des champs/priorités, commentaires et chronologie ; messages français de validation et de conflit ; recherche, filtres persistants et pagination ; parcours responsive sans JavaScript.
5. **Pilotage — livré** : indicateurs testés, répartitions, charge active, file priorisée, créations et activités récentes, liens filtrés et vue personnelle en Blade.
6. **Compatibilité PostgreSQL 18** : migrations, protections d’intégrité et suite complète sur deux moteurs, SQLite conservé localement.
7. **Finition de la vitrine** : parcours de démonstration, vérifications clavier/mobile et documentation finale.

Le dépôt GitHub est privé : [`Medyour/deskops`](https://github.com/Medyour/deskops). La CI ne publie aucun site ni artefact de déploiement. Aucun hébergement ou domaine n’est configuré. Le contrat détaillé du MVP fait foi pour les prochaines étapes métier.
