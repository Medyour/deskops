# DeskOps — exploitation de la démonstration sur App Platform

État de production de démonstration validé le 17 octobre 2026. L’application publique est disponible sur [https://deskops.mkirchel.com](https://deskops.mkirchel.com) et la base contient exclusivement des données fictives. Ce document décrit l’exploitation prudente de la configuration existante ; aucune commande de création, de mise à jour ou de déploiement n’est nécessaire pour le consulter ou valider l’App Spec.

## Budget et composants

Budget minimum : **12 USD/mois**, hors durée d’exécution des jobs, taxes et éventuels suppléments. Service `apps-s-1vcpu-0.5gb` : 5 USD/mois ; base de développement de 512 MiB : 7 USD/mois. Le job quotidien est facturé seulement pendant son exécution, pas pendant l’attente entre deux exécutions ; il n’est pas un worker permanent. Aucun autoscaling, stockage externe ou IP de sortie dédiée.

Sources : [tarification officielle](https://docs.digitalocean.com/products/app-platform/details/pricing/), [jobs natifs et facturation](https://docs.digitalocean.com/products/app-platform/how-to/manage-jobs/).

| Composant             | Configuration validée                                           | Effet                                                                                |
| --------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Application `deskops` | `region: lon`                                                   | Londres, datacenter LON1                                                             |
| Réseau                | VPC `default-lon1`, choisi manuellement                         | Application et base dans le même VPC                                                 |
| Domaine principal     | `deskops.mkirchel.com`, `PRIMARY`, sans `zone`                  | DNS conservé chez Cloudflare                                                         |
| Service `web`         | Une instance 512 MiB, port 8080, Apache/PHP 8.3                 | Seul composant HTTP                                                                  |
| Base `deskops-db`     | `engine: PG`, version `18`, `production: false`                 | Base de développement, pas un cluster géré de production                             |
| Job `prepare-demo`    | `PRE_DEPLOY`, une instance minimale                             | Contrôle du périmètre, migrations forcées, reset exclusif, vérification des fixtures |
| Job `reset-demo`      | `SCHEDULED`, `0 4 * * *`, `Europe/Paris`, une instance minimale | Reset quotidien à 04:00 heure de Paris                                               |

Les changements de saison restent gérés par le fuseau ; 04:00 est hors de l’heure sautée ou répétée. Chaque source est le dépôt privé `Medyour/deskops`, branche `main`, avec `deploy_on_push: false`. Un push ne déclenche aucun déploiement.

Le domaine DigitalOcean `deskops-9f7a8.ondigitalocean.app` est limité par une correspondance d’autorité exacte puis redirigé en HTTPS 301 vers `deskops.mkirchel.com`. La redirection n’a pas de champ `uri` : le chemin et les paramètres de la requête restent donc intacts. La règle suivante achemine normalement `/` et ses sous-chemins vers `web`, notamment sur le domaine principal.

Cloudflare contient un CNAME `deskops` vers `deskops-9f7a8.ondigitalocean.app` en mode **DNS only**. L’App Spec omet volontairement `zone`, puisque DigitalOcean ne gère pas le DNS. Propriétés confrontées à la [référence App Spec](https://docs.digitalocean.com/products/app-platform/reference/app-spec/). La [liste officielle des régions App Platform](https://docs.digitalocean.com/reference/pydo/reference/apps/list_regions/) associe `lon` à `lon1`.

## Variables et secret manuel

Toutes les variables déclarées sont au niveau de chaque composant avec portée `RUN_TIME`. Aucun secret ou URL de connexion n’est disponible pendant le build. `APP_KEY` est volontairement **absente du YAML**, sans valeur vide, fictive ou exemple chiffré.

| Groupe         | Valeurs attendues                                                                                                           |
| -------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Application    | `APP_ENV=production`, `APP_DEBUG=false`, `APP_DEMO_MODE=true`, `APP_URL=${APP_URL}`                                         |
| PostgreSQL     | `DB_CONNECTION=pgsql`, `DATABASE_URL=${deskops-db.DATABASE_PRIVATE_URL}` ; liaison chiffrée dans les variables du composant |
| Persistance    | `CACHE_STORE=database`, `SESSION_DRIVER=database`                                                                           |
| Cookies        | Secure et HTTP-only activés, `SameSite=lax`, domaine laissé à l’hôte courant                                                |
| Exécution      | `QUEUE_CONNECTION=sync`, aucun worker ; `MAIL_MAILER=array`, aucun envoi externe                                            |
| Logs et langue | `LOG_CHANNEL=stderr`, niveau warning, locale et repli français ; stockage temporel UTC                                      |
| Proxy          | `TRUSTED_PROXIES=REMOTE_ADDR` : approuver le socket d’entrée courant, pas tous les réseaux                                  |

Le proxy ne peut fournir que l’adresse client, le protocole et le port via les en-têtes X-Forwarded. X-Forwarded-Host et X-Forwarded-Prefix sont ignorés. Cette configuration suppose l’entrée par le proxy App Platform, sans accès direct public au conteneur. Cloudflare ne proxifie pas le trafic : son rôle est limité au DNS.

Créer **manuellement** une clé Laravel de 32 octets aléatoires, encodée en base64 avec son préfixe Laravel. Saisir la **valeur complète `base64:…`**, préfixe `base64:` inclus, sans guillemets ni retour à la ligne. Utiliser un terminal privé ou un gestionnaire de secrets ; ne jamais la générer dans un journal CI, la copier dans une PR, la partager ou l’inscrire dans un fichier suivi. Ajouter `APP_KEY` uniquement sur **`web`**, activer **Encrypt / SECRET** et choisir **Run Time**. Les jobs SQL n’ont pas besoin de la clé et ne la reçoivent pas. Conserver une copie dans un gestionnaire de secrets ; conserver la même clé pendant les redéploiements et rollbacks.

Le démarrage web refuse une clé absente ou invalide avant la compilation de configuration. Aucun `key:generate` n’est exécuté dans le build ou dans un job. Sources : [variables runtime, chiffrement et bindables](https://docs.digitalocean.com/products/app-platform/how-to/use-environment-variables/).

## Configuration externe à préserver

1. Conserver Londres/LON1, une seule instance web à 5 USD/mois, la base **de développement** à 7 USD/mois, les deux jobs minimaux et l’auto-déploiement désactivé.
2. Conserver le domaine principal et la redirection 301 du domaine DigitalOcean. Le CNAME Cloudflare reste en mode **DNS only** ; ne pas ajouter `zone` à l’App Spec tant que le DNS est géré par Cloudflare.
3. Conserver l’application et `deskops-db` dans le **même VPC**. `DATABASE_PRIVATE_URL` exige cette colocalisation. Le VPC `default-lon1` a été **sélectionné manuellement** dans le panneau DigitalOcean.
4. Ne pas versionner l’UUID du VPC : il est propre au compte et rendrait ce modèle non portable. Lors d’une recréation contrôlée, sélectionner `default-lon1` manuellement et vérifier l’appartenance de l’application et de la base avant tout déploiement.
5. Conserver `APP_KEY` uniquement sur `web`, chiffrée et de portée Run Time. Elle doit rester absente des jobs, du dépôt, des sorties et des captures.
6. Vérifier le devis après chaque changement de taille ou de composant. La validation hors ligne ne contrôle ni l’association VPC réelle, ni les permissions GitHub, ni la facturation du compte.

Sources : [création d’application](https://docs.digitalocean.com/products/app-platform/how-to/create-apps/), [VPC App Platform](https://docs.digitalocean.com/products/app-platform/how-to/enable-vpc/). Les commandes `doctl apps create`, `doctl apps update` et les actions de déploiement sont des opérations réelles : elles ne font partie d’aucune CI de ce dépôt et ne servent pas aux validations locales.

## Build et déploiements manuels

L’image multi-stage utilise des images officielles épinglées par digest pour PHP 8.3/Apache, Node 24.19.0 et Composer 2.10.3. npm 11.17.0 suit `packageManager`. `.do/build-production.sh` installe les dépendances verrouillées, vérifie les versions, effectue les audits et construit Vite. Les paquets Debian proviennent de leurs dépôts de sécurité ; mettre à jour et retester les digests périodiquement plutôt que désactiver un audit.

La partie PHP installe sans dépendances de développement et prépare les caches de routes, événements et vues. **Elle supprime le cache de configuration et ne le reconstruit pas.** Celui-ci est créé seulement au démarrage web, après validation des variables runtime et de `APP_KEY`. Les assets prennent également en compte les vues de pagination Laravel. Aucun `.env`, SQLite, certificat, `vendor` local, `node_modules` local ou cache local n’entre dans le contexte Docker. Source : [construction Dockerfile App Platform](https://docs.digitalocean.com/products/app-platform/reference/dockerfile/).

Lors de chaque déploiement manuel, le job `PRE_DEPLOY` exécute successivement :

```sh
php artisan deskops:production-check --database --no-interaction
php artisan migrate --force --no-interaction
php artisan deskops:demo-reset --force --exclusive --no-interaction
php artisan deskops:production-check --ready --no-interaction
```

Le script s’arrête au premier échec. Le contrôle avant migration refuse des comptes ou tickets non-démo, y compris une ancienne base sans marqueurs. Le reset revérifie ce périmètre **dans sa transaction** ; il ne revendique aucun compte existant. Une base vide dédiée est acceptée. Aucun `migrate:fresh`, `migrate:reset`, rollback forcé ou contournement des triggers n’est utilisé. L’échec d’un [job avant déploiement bloque la publication](https://docs.digitalocean.com/products/app-platform/getting-started/migrate-from-heroku/).

Résultat initial attendu : 3 comptes explicitement démo, 12 tickets, 24 commentaires et 60 activités. Les tables `sessions`, `cache` et `cache_locks` existent déjà dans les migrations Laravel ; aucune migration en double n’est ajoutée. Le web exécute le contrôle `--web`, compile la configuration runtime, puis démarre Apache sur `public/` en port 8080.

## Contrôles après déploiement et logs

1. Consulter Activity : build réussi, `prepare-demo` réussi, service prêt. Consulter les logs de chaque composant via le panneau, sans afficher ses variables d’environnement.
2. Vérifier `GET https://deskops.mkirchel.com/health` : HTTP **204**, corps vide, aucune session ni requête PostgreSQL. C’est un contrôle du serveur HTTP, pas un diagnostic de disponibilité de la base.
3. Vérifier que le domaine DigitalOcean répond en **301** : `/` doit cibler `https://deskops.mkirchel.com/`, `/health` doit cibler `https://deskops.mkirchel.com/health` et `/tickets?status=open` doit cibler `https://deskops.mkirchel.com/tickets?status=open`. En suivant la redirection de `/health`, la réponse finale doit être 204.
4. Ouvrir la connexion et utiliser les deux boutons d’exploration. Vérifier rôle administrateur/agent, bandeau fictif, navigation et cookies Secure/HTTP-only/SameSite=lax. Aucun mot de passe à chercher ou publier.
5. Vérifier le tableau de bord administrateur : 12 total, 9 actifs, 3 non assignés actifs, 3 priorités Haute/Urgente actives, 1 urgent actif, 3 terminés et 5 dans « À traiter ». Pour Alex Bernard : 5 tickets assignés, 3 actifs, 2 terminés et aucun non assigné.
6. Ajouter uniquement un commentaire fictif, vérifier sa persistance PostgreSQL et sa présence dans la chronologie, puis laisser le reset quotidien restaurer la fixture.
7. Vérifier l’absence d’inscription publique, récupération de mot de passe, route de reset ou outil de maintenance web. Les jobs et commandes Artisan ne sont pas routables.
8. Pour un contrôle console sans divulgation :

```sh
php artisan deskops:production-check --web --database --no-interaction
```

`--ready` vérifie les nombres exacts de la fixture et s’utilise juste après un reset. Pendant l’exploration, des tickets fictifs supplémentaires sont normaux. Les logs Laravel vont vers stderr et retirent les URL de connexion des messages, contextes et exceptions. Apache ne journalise ni URL, ni query string, ni cookie, ni en-tête d’authentification. Ne jamais utiliser `env`, `config:show database`, `config:show app.key`, un dump ou un mode trace pour vérifier la connexion. Source : [logs et déploiements](https://docs.digitalocean.com/products/app-platform/how-to/manage-deployments/).

L’URL PostgreSQL injectée demeure la source de vérité. Conserver son `sslmode`, généralement `require` pour cette base de démonstration ; ne pas imposer `verify-full` sans certificat racine ou magasin de confiance approprié. La CI utilise seulement `sslmode=disable` sur son service PostgreSQL éphémère sans accès externe.

## Dépannage d’une base de développement bloquée

Après les premiers déploiements échoués, la première base de développement est restée bloquée pendant son provisionnement et PostgreSQL refusait la création d’objets dans le schéma `public`. Elle était vide, dédiée à la démonstration et jetable. Elle a donc été supprimée puis recréée conformément au [correctif officiel DigitalOcean pour `permission denied for schema public`](https://docs.digitalocean.com/support/how-do-i-fix-a-permission-denied-for-schema-public-error-in-my-dev-database/), après quoi le déploiement a pu se terminer normalement.

Cette procédure est un dernier recours limité à une base de développement **vide et explicitement jetable**. Avant toute suppression, vérifier l’absence de données à conserver, l’absence d’usage extérieur et la possibilité de reconstruire entièrement la fixture. Ne jamais appliquer cette procédure à une base de production, à une base gérée, à une base partagée ou à des données devant être conservées : diagnostiquer les permissions, sauvegarder et demander une revue opérationnelle à la place.

## Reset quotidien

Chaque jour à 04:00 Europe/Paris, `reset-demo` contrôle la base dédiée, appelle `deskops:demo-reset --force --exclusive`, puis vérifie la fixture. Le verrou database partagé expire après 300 secondes et se libère aussi après exception. La réservation SQL évite le chevauchement avec les mutations publiques. Un échec provoque un rollback complet, un statut de job en échec et des sorties générales sans connexion affichée. Contrôler ensuite l’état du job dans Activity ; ne jamais lancer le reset de la base en ligne depuis un poste local.

Les ajouts démo sont supprimés et les lignes initiales restaurées avec leurs identifiants et dates fixes. Les sessions démo sont révoquées, y compris les sessions anciennes encore présentes en mémoire grâce au compteur de génération. Un visiteur doit se reconnecter après le reset ; son ancienne requête ne peut modifier la fixture reconstruite. Les hashes de mots de passe ne sont ni affichés ni renouvelés. Deux exécutions rendent les mêmes données métier. Le job refuse toute base contenant des comptes ou tickets non-démo ; le reset général conserve également ses protections de relations croisées.

## Rollback et destruction

Pour revenir en arrière, ouvrir Activity et choisir Rollback à côté de l’un des dix derniers déploiements réussis. La cible doit être dans la même région et conserver les mêmes ressources et la même configuration de base de données. Examiner ALL SETTINGS DIFF : App Platform restaure le code, la configuration et l’App Spec de cette cible. Vérifier la compatibilité de cet ancien code avec le schéma courant avant de confirmer et laisser le déploiement automatique désactivé. Le rollback ne restaure pas les données et ne doit pas lancer automatiquement `migrate:rollback`. Les migrations restent compatibles avec l’ancienne application tant qu’un retour est nécessaire. Conserver `APP_KEY` et vérifier à nouveau santé et connexions. Source : [conditions et procédure officielles de rollback](https://docs.digitalocean.com/products/app-platform/how-to/manage-deployments/).

Si un problème vient des variables ou du job, corriger la configuration puis lancer un déploiement manuel explicitement approuvé. Ne pas effacer la base pour contourner une erreur, hors cas vide et jetable précisément décrit dans la procédure de dépannage précédente. Consulter l’état des jobs après le rollback et vérifier que le planning correspond à la version retenue.

Pour arrêter définitivement la démonstration : planifier d’abord le retrait du domaine et du CNAME Cloudflare pour éviter un enregistrement pendant, puis supprimer l’application dans le panneau après décision explicite. Vérifier la suppression de **tous** ses composants et de la base de développement associée, puis vérifier qu’aucun cluster/base, job, réseau payant, IP dédiée ou ressource orpheline ne reste facturé. Vérifier le tableau de bord Databases et la facturation. Révoquer l’autorisation GitHub devenue inutile si elle n’est partagée avec aucune autre application. Ne pas supprimer `default-lon1` : c’est un VPC potentiellement partagé ; vérifier tous ses usages avant toute décision distincte. Source : [destruction d’applications](https://docs.digitalocean.com/products/app-platform/how-to/destroy-app/).

## Limites de la base de développement

La base de développement **n’a pas de sauvegarde intégrée** et n’est pas une offre gérée de production sauvegardée. Elle est adaptée à cette démonstration recréable, pas à des données réelles à conserver. Une perte ou une destruction peut exiger une reconstruction de la fixture.

DigitalOcean permet une conversion ultérieure vers une base gérée. Cette opération change le budget et demande une revue distincte : sauvegardes, capacité, réseau privé, TLS, nouveaux bindables et compatibilité des migrations. Ne jamais activer ces jobs de reset sur une base contenant des données réelles. Source : [gestion et conversion des bases App Platform](https://docs.digitalocean.com/products/app-platform/how-to/manage-databases/).

## Validations reproductibles, sans jeton DigitalOcean

```sh
doctl apps spec validate --schema-only .do/app.yaml
composer run check
php vendor/bin/pest --compact --order-by=random --random-order-seed=20261013
docker build --file .do/Dockerfile --tag deskops-production-test .
```

`--schema-only` ne résout pas les ressources du compte et ne crée rien. Le validateur officiel 1.185.0 est téléchargé avec contrôle SHA-256 en CI, sans authentification et sans recréer le contexte OAuth supprimé `deskops-deploy`. Les tests structurels parsèment aussi le YAML avec Symfony YAML déjà installé ; aucune dépendance n’est ajoutée.

Les suites SQLite et PostgreSQL 18 tournent en ordre normal et aléatoire, avec les caches Laravel. Une troisième CI construit réellement l’image, applique les migrations sur PostgreSQL sous un rôle non superutilisateur, exécute les jobs sans `APP_KEY`, démarre Apache avec une clé éphémère runtime, teste les deux connexions et les cookies, crée un ticket fictif puis effectue deux resets. Elle vérifie les sessions révoquées et le 204 même pendant une interruption de PostgreSQL. Aucune image n’est publiée dans un registre.

La machine locale dispose de PHP et Node mais pas de Docker/PostgreSQL. Le script de build est également exécuté dans une copie isolée sans `.env`, sans toucher à la base ou aux dépendances habituelles ; la construction du conteneur et le scénario PostgreSQL sont validés sur les runners éphémères GitHub, sans installation de service local.
