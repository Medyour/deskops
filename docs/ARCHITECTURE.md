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
- `app/Console/Commands/PrepareDemoAccounts.php`, `database/seeders/DemoUserSeeder.php` et `DemoTicketSeeder.php` : accès locaux, identités et fixture déterministes.
- `routes/web.php` : vitrine publique, espaces protégés, pages de tickets et cinq mutations sous session/CSRF ; routes d’authentification enregistrées par Fortify.
- `resources/views/components` : layout, layout d’authentification et retours de formulaire réutilisés.

Les rôles utilisent un enum PHP et une contrainte SQL, sans package de permissions. Le rôle reste exclu du mass assignment. Un middleware `can` applique la Gate ; le test d’accès HTTP garantit qu’un agent obtient 403 même en ouvrant directement l’URL.

## Cœur métier des tickets

Le code conserve les répertoires Laravel habituels :

- Modèles Eloquent `Ticket`, `Comment`, `TicketActivity`, relations, casts, factories et seeders.
- Enums `TicketStatus`, `TicketPriority` et `TicketActivityType` ; le graphe pur est défini dans `TicketStatus`.
- `TicketPolicy` pour les autorisations ; Form Requests pour les données d’entrée.
- `TicketController` rend la liste, la création, le détail et l’édition. Il applique les policies ; la liste passe par `IndexTicketsRequest` et le scope Eloquent `filtered`.
- `TicketMutationController` délègue les cinq mutations aux actions et redirige vers le détail. Il présente les conflits HTML en 409 ; les appels qui attendent du JSON conservent la réponse 409 initiale.
- Petites actions explicites dans `app/Actions/Tickets` pour les mutations qui combinent règles, transaction et historique (`CreateTicket`, `UpdateTicket`, `AssignTicket`, `ChangeTicketStatus`, `AddComment`). Pas de classe abstraite commune.
- `TicketWorkspace` prépare les relations et options d’affichage partagées entre détail et conflit. Les transitions disponibles viennent de `ChangeTicketStatus` (graphe et policy) ; la possibilité de libérer l’assignation vient de `AssignTicket`. Les vues ne réimplémentent pas ces règles.
- La liste charge l’agent et la dernière date d’activité avec une sous-requête d’agrégation. Le détail charge commentaires/auteurs et activités/acteurs en lots, ainsi que les noms des agents cités dans l’historique. Aucune requête par ligne. Les filtres validés seuls sont conservés dans la pagination.
- `DashboardController` délègue à `OperationsDashboard`, service de lecture dans `app/Actions/Tickets`. `DashboardRequest` valide le périmètre et applique la policy de lecture ; la route reste sous `auth`. Les vues ne calculent aucun indicateur métier.
- `TicketQueue` définit les vues opérationnelles et les ensembles de statuts actifs/terminés et de priorités élevées à partir des enums métier ; le scope `Ticket::inQueue` porte leurs prédicats SQL, partagé par les calculs et les liens vers la liste. Aucun service de mutation n’est changé. `IndexTicketsRequest` valide le filtre GET `queue`, combinable avec les filtres existants. « Mes tickets » utilise exclusivement l’identité de la session, jamais un identifiant reçu du navigateur.
- Le tableau de bord agrège par statut/priorité (au plus 20 groupes) pour tous les compteurs et répartitions, y compris Terminés (Résolu ou Fermé). Aucun indicateur ne dépend d’une horloge ; l’heure courante sert uniquement à afficher le moment du chargement. Les dates métier de résolution/fermeture restent inchangées. La charge utilise `withCount` limité aux tickets actifs, sans charger les tickets des agents. Les trois aperçus sont limités en SQL à cinq lignes ; agents assignés, tickets et acteurs sont chargés en lots. Les activités ne sélectionnent ni `changes` ni `reason`. Le nombre de requêtes reste borné lorsque les tickets, activités et agents augmentent. Les index existants couvrent les créations et activités récentes et l’assignation/statut ; aucun nouveau schéma ni index spéculatif n’est nécessaire.

Une action applique la règle, écrit les données et l’activité dans une seule transaction. Les requêtes conditionnelles protègent la prise en charge concurrente sous SQLite. Éviter les observers génériques pour l’audit : ils ne connaissent pas toujours l’acteur ou le motif métier et peuvent produire des doublons au seeding.

`TicketInput` partage les règles de validation entre les Form Requests et les actions, afin que le seed et les appels internes ne contournent pas les validations HTTP. Les Form Requests contrôlent la structure des données ; l’exigence de motif liée au statut courant est évaluée dans l’action après le contrôle de version, pour garantir le conflit 409 d’une requête obsolète. Les attributs détenus par le serveur sont refusés à la création. `TicketPolicy`, découvert par Laravel selon la convention, applique les permissions ; les actions relisent l’acteur et le ticket avant de vérifier les droits.

`TicketMutation` regroupe uniquement le mécanisme répété de transaction/version/historique. Pour une mutation existante, la première instruction SQL est une écriture conditionnelle `WHERE id = ? AND version = ?`, sans changer de valeur. Elle réserve le writer SQLite avant la relecture des permissions et règles métier. Chaque mutation effective augmente `version` une fois ; les commentaires participent à cette version du ticket. Un échec de version ou un code SQLite busy/locked devient `StaleTicket` (HTTP 409). Une erreur d’activité annule la mutation entière. Aucun `lockForUpdate` n’est supposé fournir un verrou de ligne SQLite ; le délai d’attente local est de cinq secondes.

Les trois migrations métier ajoutent les tickets, activités et commentaires. Les enums deviennent des contraintes SQL ; des triggers SQLite vérifient les longueurs, la version positive, le rôle agent de l’assigné et la cohérence statut/dates. Les clés étrangères restreignent les suppressions. Les index couvrent statut/date, priorité/date, assigné/statut et chronologie par ticket/date/id. La référence est un accessor dérivé de la clé primaire auto-incrémentée, sans compteur distinct ni valeur aléatoire.

Les activités et commentaires n’ont pas de `updated_at`. Des triggers refusent leur édition, suppression et remplacement SQL, y compris un remplacement passant par l’unicité de l’activité de création. Les activités stockent les seuls changements utiles et le motif ; un commentaire est référencé par identifiant sans dupliquer son corps. `AddComment` alimente la fixture et le formulaire de commentaires internes ; aucun parcours d’édition ou de suppression n’est ajouté.

`UpdateTicket` réutilise `TicketMutation` et la policy `update`. Il valide les mêmes champs qu’à la création, refuse les attributs détenus par le serveur et exige une priorité et une version. Une édition effective augmente la version une fois et ajoute une activité par catégorie réellement modifiée (`ticket_updated`, `priority_changed`) dans une seule transaction. Une répétition à jour ne produit aucune écriture.

Les vues Blade échappent tous les textes et fonctionnent sans JavaScript. Les formulaires partagent les règles et messages français de `TicketInput` via `TicketFormRequest`. Les erreurs renvoient vers une page GET nommée, y compris après une reprise de conflit. Le 409 HTML affiche le brouillon validé sans le stocker dans une URL, les valeurs actuelles et une nouvelle tentative explicite avec la version actuelle. Si la permission a disparu, le brouillon reste lisible mais le formulaire est masqué. Le lien vers le ticket actuel ouvre un nouvel onglet pour conserver le brouillon affiché. Aucun réessai automatique.

Les heures sont rendues en Europe/Paris ; le stockage reste UTC. Les tableaux deviennent des cartes sur mobile/tablette. Le détail place les actions avant les commentaires sur petit écran, conserve les labels, erreurs associées, focus visibles et chronologie lisible sans couleur seule.

`DemoTicketSeeder` construit la fixture par les mêmes actions métier, sous une horloge UTC temporaire restaurée après chaque opération. L’ensemble des comptes/tickets et la rotation des accès sont transactionnels. Une relance reconnaît les créations initiales immuables et conserve les mutations et ajouts ; une base incompatible est refusée sans modification partielle. Aucun reset implicite ni marqueur de seed supplémentaire n’est créé.

Pas de repository autour d’Eloquent, bus de commandes, architecture par couches, CQRS ou système générique de workflow. Extraire du code lorsqu’une répétition réelle apparaît.

## Tests et contrôles

Pest 4 repose sur PHPUnit. Les tests Feature utilisent une base SQLite en mémoire et `RefreshDatabase`. Ils couvrent les flux HTTP et console, les refus d’accès, les erreurs de validation, la rotation des sessions, la récupération d’accès et les seeders. Le transport mail est simulé ; aucun e-mail externe n’est envoyé.

Les tests Unit couvrent les 25 couples de statuts. Les tests Feature couvrent les huit transitions permises, les transitions interdites, la matrice des permissions, les entrées invalides et les versions obsolètes. Les tests d’intégrité passent aussi par SQL direct et simulent une impossibilité réelle d’écrire l’activité pour vérifier les rollbacks. Un fichier SQLite temporaire dédié permet deux connexions indépendantes, deux versions observées et un writer concurrent occupé. Un autre vérifie l’installation depuis une base vide, deux commandes de démonstration, le rollback des tables métier peuplées, la disparition des triggers, la réapplication et le rollback complet. La fixture complète est vérifiée à horloge figée, ainsi que sa relance après mutation et le refus d’une base incompatible. Les tests de l’espace tickets couvrent pages et accès, recherche littérale, filtres combinés, pagination, versions obsolètes et brouillons, erreurs françaises et retours vers une page GET, commentaires, contenu hostile échappé, matrice des actions affichées et nombre de requêtes borné. L’édition est également testée pour son historique par catégorie, ses refus, les répétitions et le rollback en cas d’échec d’activité. Les tests du tableau de bord couvrent les valeurs exactes de la fixture, les catégories et files vides, les deux rôles, la vue personnelle, les priorités et assignations, les statuts terminés indépendamment des dates historiques, les tentatives de substitution de l’identité personnelle, les liens et filtres combinés/paginés, les tris stables et les limites SQL, les textes échappés et un nombre de requêtes constant lorsque les données augmentent.

Aucune suite JavaScript artificielle pour les fondations : `app.js` ne contient aucune logique. Prettier contrôle les sources front et le build Vite vérifie leur assemblage. Au premier îlot Vue, ajouter Vitest et les tests d’interactions qui le justifient.

`composer run check` constitue le contrôle commun local/GitHub Actions : manifeste Composer, exigences de plateforme, audits Composer/npm, Pint en vérification, tests Laravel, format front/documentation, script de tests JavaScript lorsqu’il existe et build de production. Elle échoue au premier contrôle en erreur. Les fichiers de verrouillage Composer et npm sont versionnés. Les tests métier ne nécessitent pas le serveur Herd.

La CI commence par `composer run setup` sur une base SQLite neuve, sous PHP 8.3. Node et Composer suivent `.node-version` et `.composer-version` ; npm suit `packageManager`. Les actions sont épinglées par SHA avec un jeton en lecture seule. Les caches contiennent uniquement les téléchargements des gestionnaires de dépendances, jamais les données ou identifiants de l’application.

## Limites assumées

L’espace administration est un écran protégé, sans CRUD de comptes. Les pages et mutations de tickets, la chronologie, la recherche, les filtres et le tableau de bord opérationnel sont disponibles. Aucun SLA, délai ou indicateur de performance individuel n’est introduit. Les comptes de démonstration sont exclusivement locaux. Les sessions et hashes nécessaires au fonctionnement restent dans une base ignorée par Git ; les clés locales restent dans `.env`, également ignoré.

Le transport `MAIL_MAILER=log` inscrit les liens de récupération dans `storage/logs/laravel.log`. Ce journal reste local et ignoré ; aucun SMTP ou secret externe n’est configuré. L’accès public à ce démonstrateur et sa politique d’exploitation devront être décidés lors d’une étape ultérieure, hors de cette tâche.
