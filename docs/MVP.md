# DeskOps — contrat du MVP

## Produit et périmètre

DeskOps est un mini-centre de support **interne**, destiné à une petite équipe. Il démontre l’authentification, les autorisations, un workflow métier explicite et sa traçabilité. Le client est un contact du ticket, sans compte ni portail.

**Étape 1 livrée :** vitrine, connexion/déconnexion, session persistante optionnelle, récupération et confirmation du mot de passe, deux rôles, espaces protégés, trois comptes locaux, outillage et tests.

**Cœur métier livré :** schéma des tickets, enums, autorisations, création, assignation, transitions, ajout interne de commentaires, historique immuable et fixture déterministe. Les mutations disposent de points d’entrée web authentifiés, reliés aux pages de tickets.

**Espace tickets livré :** liste paginée et filtrable, création, détail, édition des champs/priorités, commentaires, chronologie et actions autorisées. Les conflits 409 présentent la saisie non enregistrée et les valeurs actuelles, avec reprise explicite après vérification.

**Tableau de bord livré :** accueil authentifié, indicateurs globaux, répartitions, charge active par agent, file priorisée, tickets et activités récents ; vue « Mes tickets » pour les agents. Les liens réutilisent les mêmes filtres de lecture que les calculs.

**MVP restant :** finition de la vitrine.

Hors périmètre : inscription publique, gestion graphique des comptes/rôles, portail client, e-mail entrant ou sortant des tickets, pièces jointes, SLA, notifications métier, chat, temps réel, API publique, multi-tenant, suppression de tickets et automatisation des statuts. La récupération d’accès utilise le transport mail local de Laravel.

## Identité et autorisations

Deux valeurs de rôle persistées : `admin` (administrateur) et `agent` (agent). Le rôle par défaut est agent. Le rôle est exclu des attributs assignables depuis une requête et contrôlé par la base. Le serveur vérifie les permissions, même si un lien ou un bouton est masqué.

| Opération                                           | Administrateur                  | Agent                                       |
| --------------------------------------------------- | ------------------------------- | ------------------------------------------- |
| Lire/rechercher tous les tickets et leur historique | Oui                             | Oui                                         |
| Créer un ticket                                     | Oui                             | Oui                                         |
| Modifier titre, description, contact ou priorité    | Tout ticket non fermé           | Ticket qui lui est assigné, non fermé       |
| Ajouter un commentaire interne                      | Tout ticket non fermé           | Ticket qui lui est assigné, non fermé       |
| Assigner/réassigner/libérer un ticket               | Oui, sous les règles ci-dessous | Non                                         |
| Prendre un ticket non assigné                       | Oui, en l’assignant à un agent  | Oui, uniquement à soi                       |
| Changer le statut                                   | Tout ticket, selon le graphe    | Ticket qui lui est assigné, selon le graphe |
| Fermer un ticket résolu / rouvrir un ticket fermé   | Oui                             | Non                                         |
| Consulter le tableau de bord global                 | Oui                             | Oui, avec vue « Mes tickets »               |
| Accéder à l’espace administration                   | Oui                             | Non                                         |

Un administrateur n’est pas un agent assignable. La création produit toujours un ticket nouveau et non assigné ; le formulaire de création n’accepte ni rôle, ni statut, ni auteur imposés par le navigateur. Un agent doit ensuite prendre le ticket pour le modifier. L’auteur de création n’a aucun privilège permanent sur celui-ci.

## Tickets et stockage

| Entité              | Champs et règles                                                                                                                                                                                                                                                                                          |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tickets`           | `id`, `title` (5–160 caractères), `description` (10–10 000), `requester_name` (2–120), `requester_email` (adresse valide, max. 254), `status`, `priority`, `created_by`, `assigned_to` nullable, `version` (entier positif, initialement 1), `resolved_at` nullable, `closed_at` nullable, timestamps UTC |
| `comments`          | `id`, `ticket_id`, `author_id`, `body` (1–5 000 caractères non blancs), `created_at`                                                                                                                                                                                                                      |
| `ticket_activities` | `id`, `ticket_id`, `actor_id`, `type`, `changes` JSON, `reason` nullable (5–500 caractères lorsqu’exigé), `created_at`                                                                                                                                                                                    |

Référence affichée : `DS-0001`, calculée à partir de l’identifiant entier (au moins quatre chiffres ; reste unique après 9999). Le contact est dénormalisé sur le ticket : pas de modèle Client à ce stade. Les textes sont stockés comme texte brut et échappés à l’affichage ; ni HTML, ni Markdown interprété.

Clés étrangères vers les utilisateurs et tickets avec suppression restreinte. Les comptes ne sont pas supprimés dans le MVP. Index : statut/date, priorité/date, agent/statut, activités et commentaires par ticket/date/id. Pas de moteur de recherche externe.

## Statuts et transitions

| Valeur             | Libellé              | Sens                                |
| ------------------ | -------------------- | ----------------------------------- |
| `new`              | Nouveau              | Reçu, pas encore pris en charge     |
| `in_progress`      | En cours             | Un agent traite la demande          |
| `waiting_customer` | En attente du client | L’équipe attend une information     |
| `resolved`         | Résolu               | Une solution a été apportée         |
| `closed`           | Fermé                | L’administrateur clôture le dossier |

| Départ               | Arrivée autorisée    | Condition supplémentaire                         |
| -------------------- | -------------------- | ------------------------------------------------ |
| Nouveau              | En cours             | Un agent doit être assigné                       |
| En cours             | En attente du client | Motif obligatoire                                |
| En cours             | Résolu               | Résumé de résolution obligatoire                 |
| En attente du client | En cours             | Aucune                                           |
| En attente du client | Résolu               | Résumé de résolution obligatoire                 |
| Résolu               | En cours             | Motif de réouverture obligatoire                 |
| Résolu               | Fermé                | Administrateur uniquement                        |
| Fermé                | En cours             | Administrateur, motif obligatoire, agent assigné |

Toute autre transition est refusée, y compris un passage direct de Nouveau à Résolu. Répéter le statut actuel est une opération sans effet et sans activité. Les motifs sont enregistrés dans l’activité correspondante.

Passage à Résolu : renseigner `resolved_at`. Passage à Fermé : conserver `resolved_at`, renseigner `closed_at`. Toute réouverture : vider ces deux dates. Les tickets fermés sont en lecture seule jusqu’à leur réouverture. Il n’existe ni suppression ni fermeture automatique.

## Priorités et assignation

Priorités : `low` (Basse), `normal` (Normale, par défaut), `high` (Haute), `urgent` (Urgente). Elles donnent un ordre de traitement sans déclencher un SLA. Pas de priorité calculée ou de délai implicite.

Un ticket a zéro ou un agent assigné. Seuls des utilisateurs au rôle agent peuvent être choisis. Nouveau peut être assigné ou non. En cours, En attente du client et Résolu exigent un agent assigné. Libérer l’assignation n’est possible qu’à l’état Nouveau. Changer d’agent ne modifie pas le statut. Un ticket fermé garde son dernier agent et ne peut être réassigné avant sa réouverture.

La prise en charge est atomique : si deux agents réclament un même ticket, une seule opération réussit, l’autre reçoit un conflit explicite (HTTP 409). La vérification du statut/de l’assignation et l’écriture de l’activité s’effectuent dans la même transaction. Utiliser une écriture conditionnelle sur les valeurs courantes pour SQLite ; ne pas compter sur un verrou de ligne absent de SQLite.

Précision technique : toute mutation d’un ticket existant transmet la `version` observée. Une mutation effective, y compris un commentaire ajouté, l’incrémente une fois. Une version absente ou invalide produit une erreur de validation ; une version obsolète ou une base temporairement occupée produit un conflit 409 invitant à recharger. Une répétition autorisée et à jour ne modifie ni version, ni dates, ni historique. L’assignation et les permissions sont relues dans la transaction après une écriture conditionnelle qui réserve le writer SQLite.

## Commentaires et historique

Les commentaires sont internes et visibles par toute l’équipe. Leur auteur et leur date viennent du serveur. Ils sont immuables : ni édition, ni suppression. Aucun e-mail n’est envoyé au client.

Une activité est ajoutée pour chaque création, modification des champs métier, changement de priorité, assignation, transition et commentaire ajouté. Types : `ticket_created`, `ticket_updated`, `priority_changed`, `assignment_changed`, `status_changed`, `comment_added`. Les valeurs avant/après sont stockées, ainsi que l’acteur. Un commentaire est référencé par son identifiant dans l’activité ; son texte n’est pas dupliqué. Aucun mot de passe, token ou contenu de session n’entre dans l’historique.

Une commande qui change plusieurs catégories produit une activité par catégorie, dans une transaction unique. Une opération sans changement n’ajoute rien. La chronologie est ordonnée par `created_at` puis `id`. Les tests doivent prouver qu’une opération refusée ne laisse ni modification ni activité.

SQLite refuse également les mises à jour, suppressions et remplacements d’activités ou de commentaires. Une seule activité de création est permise par ticket. Les activités de création conservent les champs métier initiaux ; celles d’assignation et de statut conservent seulement les valeurs réellement changées, les dates métier concernées et le motif éventuel. La version technique n’est pas dupliquée dans l’historique.

## Recherche et filtres

Liste paginée par 20, nouveaux tickets d’abord (`created_at DESC, id DESC`). Recherche `q` (0–100 caractères, espaces externes retirés) sur référence exacte, titre, nom et e-mail du contact. La recherche de référence est insensible à la casse. Pour les textes, recherche par sous-chaîne avec la casse ASCII de SQLite ; la recherche sans accents n’est pas promise dans le MVP. Les caractères `%` et `_` sont traités littéralement, pas comme jokers SQL.

Filtres GET combinables avec un ET : `status`, `priority`, `assignee` (id d’agent, `me`, `unassigned`) et `queue` (vue opérationnelle définie ci-dessous). Les valeurs inconnues sont rejetées avec une erreur de validation ; elles ne disparaissent pas silencieusement. Les paramètres restent dans l’URL et dans les liens de pagination. Un bouton « Réinitialiser » efface les filtres. Afficher le nombre de résultats et un état vide utile. Aucun tri ou recherche côté navigateur nécessaire.

## Tableau de bord métier

`GET /dashboard` est l’accueil après connexion et depuis le logo authentifié. La vue Équipe couvre tous les tickets accessibles pour les deux rôles. `scope=mine`, réservé aux agents, applique `assigned_to = utilisateur courant` à tous les indicateurs, répartitions et files. La charge ne montre alors que l’agent courant ; les activités concernent les tickets qui lui sont actuellement assignés, quel que soit leur acteur. Ce périmètre n’est pas un filtre sur l’auteur de l’activité. Un administrateur n’est pas assignable et ne dispose pas de vue personnelle. Les vues inconnues sont refusées. Aucun pourcentage de performance individuel.

Précision de vocabulaire : l’ancien ensemble « à traiter » désigne les **tickets actifs**. Le titre de file **À traiter** désigne désormais le sous-ensemble priorisé ci-dessous ; il ne change aucune règle de mutation ou de statut.

| Indicateur / file         | Définition exacte                                                                                | Filtre `queue`  |
| ------------------------- | ------------------------------------------------------------------------------------------------ | --------------- |
| Total                     | Tous les tickets du périmètre, y compris Résolu et Fermé                                         | Aucun           |
| Tickets actifs            | Statut Nouveau, En cours ou En attente du client                                                 | `active`        |
| Non assignés              | Tickets actifs dont `assigned_to IS NULL`                                                        | `unassigned`    |
| Priorités élevées actives | Tickets actifs de priorité Haute ou Urgente                                                      | `high_priority` |
| Urgents actifs            | Tickets actifs de priorité Urgente                                                               | `urgent`        |
| Terminés                  | Tous les tickets actuellement au statut Résolu ou Fermé, quelle que soit leur date de résolution | `completed`     |
| À traiter                 | Tickets actifs ET (non assignés OU priorité Haute/Urgente), chaque ticket compté une seule fois  | `attention`     |

Les répartitions par statut et par priorité couvrent tous les tickets du périmètre, avec les catégories vides affichées à zéro. La charge par agent compte uniquement ses tickets actifs ; les agents sans charge restent visibles, les administrateurs sont exclus et la file non assignée est séparée. Tri : charge décroissante, nom puis identifiant croissants.

La file À traiter montre cinq tickets maximum : Urgente, Haute, Normale, Basse, puis `created_at ASC, id ASC` à priorité égale. Son lien vers la liste conserve le tri habituel de la liste (créations les plus récentes), sans perdre l’appartenance à la file. Les cinq tickets récents sont les dernières **créations**, tous statuts, ordonnées par `created_at DESC, id DESC`. Les cinq dernières activités suivent également `created_at DESC, id DESC` et relient au détail ; seules référence, sujet, type, acteur et date sont affichés, sans métadonnées ni corps de commentaire.

Les liens vers les compteurs/files/répartitions reprennent `assignee=me` dans la vue personnelle. Les filtres opérationnels se combinent avec la recherche, le statut, la priorité et l’assignation, persistent dans l’URL/pagination et restent sélectionnés dans le formulaire de liste. Une file non assignée combinée à Mes tickets est correctement vide.

La page est calculée à chaque chargement, sans cache métier ni rafraîchissement automatique. Sur base vide, compteurs et catégories valent zéro, les agents existants ont une charge nulle et chaque file affiche un état vide. Aucun SLA, délai implicite, échéance ou durée moyenne n’est calculé.

Les heures sont stockées en UTC et affichées en Europe/Paris. Les indicateurs dépendent des statuts, priorités et assignations actuels, sans fenêtre temporelle ni horloge figée dans leurs tests. Le jeu de démonstration conserve ses dates historiques fixes. Les dates de résolution/fermeture et leurs règles métier sont conservées. Les répartitions utilisent des listes textuelles accessibles en Blade, sans dépendre des couleurs ou de JavaScript ; aucun graphique Vue n’est nécessaire.

## Données de démonstration

**Disponible aujourd’hui :** Camille Martin (`admin@deskops.example`), Alex Bernard (`alex@deskops.example`) et Sam Dubois (`sam@deskops.example`). Un administrateur, deux agents. Date d’identité fixe : 15 janvier 2026 à 09:00 UTC. Le seeder est idempotent et conserve les mots de passe existants.

La commande locale `php artisan deskops:demo` prépare les comptes et la fixture ci-dessous, puis génère un mot de passe distinct par compte, affiché une seule fois dans le terminal. Elle révoque leurs sessions, cookies de connexion persistante et liens de réinitialisation précédents. Aucune valeur secrète n’est versionnée. La commande refuse tout environnement autre que local ; les seeders refusent les environnements autres que local/testing. La stabilité des données métier n’exige pas des hashes ou des mots de passe identiques.

**Fixture métier disponible :**

| Référence | Sujet                    | Statut               | Priorité | Agent |
| --------- | ------------------------ | -------------------- | -------- | ----- |
| DS-0001   | Accès au compte client   | Nouveau              | Normale  | —     |
| DS-0002   | Demande de facture       | Nouveau              | Basse    | —     |
| DS-0003   | Service indisponible     | Nouveau              | Urgente  | —     |
| DS-0004   | Export des contacts      | En cours             | Normale  | Alex  |
| DS-0005   | Paiement refusé          | En cours             | Haute    | Sam   |
| DS-0006   | Adresse de livraison     | En cours             | Basse    | Alex  |
| DS-0007   | Import incomplet         | En cours             | Haute    | Sam   |
| DS-0008   | Justificatif manquant    | En attente du client | Normale  | Alex  |
| DS-0009   | Informations de commande | En attente du client | Normale  | Sam   |
| DS-0010   | Double notification      | Résolu               | Normale  | Alex  |
| DS-0011   | Erreur de connexion      | Résolu               | Haute    | Sam   |
| DS-0012   | Mise à jour du profil    | Fermé                | Basse    | Alex  |

Reconstruction sur une base de démonstration neuve uniquement : identifiants 1–12, aucun Faker aléatoire. Pour le ticket numéro N, création par Camille à `2026-01-15 09:00 UTC + (N-1) × 30 minutes`, contact `Client N` / `clientN@deskops.example`, description `Demande de démonstration : {sujet}. Merci de vérifier et de proposer une solution.`

Pour les tickets non nouveaux : assignation à +1 minute, passage à En cours à +2 minutes, puis En attente/Résolu à +3 minutes, Fermé à +7 minutes si applicable. Les transitions initiales sont effectuées par Camille avec le motif fixe `Scénario de démonstration` lorsque nécessaire. Commentaires à +5 minutes (Camille : `Demande enregistrée dans le support.`) et +6 minutes (agent assigné, sinon Camille : `Suivi de démonstration ajouté.`), avant l’éventuelle fermeture pour respecter la lecture seule.

Résultat attendu : 12 tickets, 24 commentaires, 60 activités (12 créations + 9 assignations + 15 transitions + 24 commentaires). Indicateurs indépendants de la date de consultation : total 12, actifs 9, non assignés actifs 3, priorités élevées actives 3, urgents actifs 1, terminés 3 ; charges actives Alex 3 / Sam 3 / file non assignée 3. La file À traiter contient 5 tickets, aperçu `DS-0003, DS-0005, DS-0007, DS-0001, DS-0002`. Répartition statuts : Nouveau 3, En cours 4, En attente 2, Résolu 2, Fermé 1 ; priorités : Basse 3, Normale 5, Haute 3, Urgente 1. Les créations récentes sont `DS-0012` à `DS-0008` ; les activités récentes ont les identifiants `60, 59, 58, 57, 56`. Le futur reset sera explicite et réservé à une base locale jetable, jamais exécuté automatiquement à l’installation.

L’initialisation exige une table `tickets` vide dont les identifiants n’ont jamais été utilisés. Les relances reconnaissent la fixture par ses activités de création immuables et conservent les tickets modifiés, les commentaires, l’historique et les tickets supplémentaires. Une fixture partielle ou des tickets sans cette origine sont refusés sans écrasement, sans création partielle de comptes et sans renouvellement des accès. Aucun reset n’est fourni dans cette étape. `setup` applique seulement les migrations ; il ne lance pas le seed.

## Critères d’acceptation du MVP

1. Les rôles et les droits sont vérifiés sur chaque mutation, avec tests positifs et négatifs.
2. Un ticket peut être créé, pris en charge, commenté, mis en attente, résolu puis fermé conformément au graphe.
3. Une transition invalide, une assignation à un administrateur ou une mutation de ticket fermé échoue sans écriture partielle.
4. Chaque mutation effective produit les activités attendues, avec auteur et valeurs avant/après.
5. Deux prises en charge concurrentes ne peuvent pas écraser l’agent gagnant.
6. Recherche, filtres, pagination, états vides et métriques correspondent au contrat.
7. Recréer la fixture donne les mêmes données et métriques, indépendamment de la date de consultation.
8. Les parcours restent utilisables au clavier, sur mobile et sans JavaScript ; le contrôle qualité local est vert.
