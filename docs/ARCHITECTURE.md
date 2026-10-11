# Architecture de DeskOps

## Décision initiale

Un monolithe Laravel 13, PHP 8.3 minimum et SQLite. Blade rend les pages, Tailwind 4 assure la présentation et Vite 8 produit les assets. Fortify fournit l’authentification par session. La version PHP du résolveur Composer est fixée à 8.3.0 pour éviter qu’une installation sous une version supérieure sélectionne une dépendance incompatible.

Les vues fonctionnent sans JavaScript. Vue n’est pas installé dans cette étape : il sera ajouté avec `@vitejs/plugin-vue` et un import dynamique ciblé uniquement pour un composant qui justifie une interaction locale (par exemple un graphique sélectionnable). Pas d’Inertia, de routeur client ou de SPA.

Les fontes système évitent une dépendance réseau pendant le build. Aucun service externe, clé d’API ou serveur Redis n’est nécessaire. SQLite stocke également les sessions et le cache ; les tâches sont synchrones tant qu’il n’existe aucun besoin de file d’attente.

## Code existant

- `app/Models/User.php` : utilisateur, hash du mot de passe, rôle typé et test du rôle administrateur.
- `app/Enums/UserRole.php` : les deux rôles fermés et leurs libellés.
- `app/Providers/AppServiceProvider.php` : Gate d’administration et politique de mot de passe.
- `app/Providers/FortifyServiceProvider.php` : vues d’authentification, reset et limitation de connexion.
- `app/Actions/Fortify/ResetUserPassword.php` : validation du nouveau mot de passe.
- `app/Console/Commands/PrepareDemoAccounts.php` et `database/seeders/DemoUserSeeder.php` : accès locaux et identités déterministes.
- `routes/web.php` : pages publiques et espaces protégés ; routes d’authentification enregistrées par Fortify.
- `resources/views/components` : layout, layout d’authentification et retours de formulaire réutilisés.

Les rôles utilisent un enum PHP et une contrainte SQL, sans package de permissions. Le rôle reste exclu du mass assignment. Un middleware `can` applique la Gate ; le test d’accès HTTP garantit qu’un agent obtient 403 même en ouvrant directement l’URL.

## Organisation à l’étape métier

Conserver les répertoires Laravel habituels. Ajouter seulement :

- Modèles Eloquent `Ticket`, `Comment`, `TicketActivity`, relations, casts, factories et seeders.
- Enums `TicketStatus` et `TicketPriority`.
- `TicketPolicy` pour les autorisations ; Form Requests pour les données d’entrée.
- Contrôleurs courts pour afficher et recevoir les formulaires.
- Petites actions explicites dans `app/Actions/Tickets` pour les mutations qui combinent règles, transaction et historique (`CreateTicket`, `AssignTicket`, `ChangeTicketStatus`, `AddComment`). Pas de classe abstraite commune.
- Scopes Eloquent pour la liste filtrable, requêtes d’agrégation pour le tableau de bord.

Une action applique la règle, écrit les données et l’activité dans une seule transaction. Les requêtes conditionnelles protègent la prise en charge concurrente sous SQLite. Éviter les observers génériques pour l’audit : ils ne connaissent pas toujours l’acteur ou le motif métier et peuvent produire des doublons au seeding.

Pas de repository autour d’Eloquent, bus de commandes, architecture par couches, CQRS ou système générique de workflow. Extraire du code lorsqu’une répétition réelle apparaît.

## Tests et contrôles

Pest 4 repose sur PHPUnit. Les tests Feature utilisent une base SQLite en mémoire et `RefreshDatabase`. Ils couvrent les flux HTTP et console, les refus d’accès, les erreurs de validation, la rotation des sessions, la récupération d’accès et les seeders. Le transport mail est simulé ; aucun e-mail externe n’est envoyé.

Ajouter les tests métier avec les cas limites et les invariants du contrat MVP. Les tests Unit deviendront utiles pour le graphe de transitions et les règles pures. Figer l’horloge dans les tests d’indicateurs. Tester le rollback de l’historique et la prise en charge concurrente avec une base SQLite fichier dédiée lorsque le workflow existera.

Aucune suite JavaScript artificielle pour les fondations : `app.js` ne contient aucune logique. Prettier contrôle les sources front et le build Vite vérifie leur assemblage. Au premier îlot Vue, ajouter Vitest et les tests d’interactions qui le justifient.

`composer run check` constitue le contrôle commun local/GitHub Actions : manifeste Composer, exigences de plateforme, audits Composer/npm, Pint en vérification, tests Laravel, format front/documentation, script de tests JavaScript lorsqu’il existe et build de production. Elle échoue au premier contrôle en erreur. Les fichiers de verrouillage Composer et npm sont versionnés. Les tests métier ne nécessitent pas le serveur Herd.

La CI commence par `composer run setup` sur une base SQLite neuve, sous PHP 8.3. Node et Composer suivent `.node-version` et `.composer-version` ; npm suit `packageManager`. Les actions sont épinglées par SHA avec un jeton en lecture seule. Les caches contiennent uniquement les téléchargements des gestionnaires de dépendances, jamais les données ou identifiants de l’application.

## Limites assumées

L’espace administration est un écran protégé, sans CRUD de comptes. Les indicateurs et l’historique métier ne sont pas encore développés. Les comptes de démonstration sont exclusivement locaux. Les sessions et hashes nécessaires au fonctionnement restent dans une base ignorée par Git ; les clés locales restent dans `.env`, également ignoré.

Le transport `MAIL_MAILER=log` inscrit les liens de récupération dans `storage/logs/laravel.log`. Ce journal reste local et ignoré ; aucun SMTP ou secret externe n’est configuré. L’accès public à ce démonstrateur et sa politique d’exploitation devront être décidés lors d’une étape ultérieure, hors de cette tâche.
