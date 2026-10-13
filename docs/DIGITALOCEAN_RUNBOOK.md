# DeskOps — exploitation de la démonstration sur App Platform

Configuration préparée le 13 octobre 2026, **sans création de ressource ni déploiement**. La base contient exclusivement des données fictives. L’App Spec référence `main` : attendre la revue et la fusion de cette préparation avant toute création d’application.

## Budget et composants

Budget de base prévu : **12 USD/mois**, hors durée d’exécution des jobs, taxes et éventuel dépassement de transfert. Service `apps-s-1vcpu-0.5gb` : 5 USD/mois ; base de développement de 512 MiB : 7 USD/mois. Vérifier le récapitulatif de facturation au moment de la création. Le job quotidien est facturé seulement pendant son exécution, pas pendant l’attente entre deux exécutions ; il n’est pas un worker permanent. Aucun autoscaling, domaine, stockage externe ou IP de sortie dédiée.

Sources : [tarification officielle](https://docs.digitalocean.com/products/app-platform/details/pricing/), [jobs natifs et facturation](https://docs.digitalocean.com/products/app-platform/how-to/manage-jobs/).

| Composant             | Configuration préparée                                          | Effet                                                                                |
| --------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| Application `deskops` | `region: lon`                                                   | Londres, datacenter LON1                                                             |
| Service `web`         | Une instance 512 MiB, port 8080, Apache/PHP 8.3                 | Seul composant HTTP                                                                  |
| Base `deskops-db`     | `engine: PG`, version `18`, `production: false`                 | Base de développement, pas un cluster géré de production                             |
| Job `prepare-demo`    | `PRE_DEPLOY`, une instance minimale                             | Contrôle du périmètre, migrations forcées, reset exclusif, vérification des fixtures |
| Job `reset-demo`      | `SCHEDULED`, `0 4 * * *`, `Europe/Paris`, une instance minimale | Reset quotidien à 04:00 heure de Paris                                               |

Les changements de saison restent gérés par le fuseau ; 04:00 est hors de l’heure sautée ou répétée. Chaque source est le dépôt privé `Medyour/deskops`, branche `main`, avec `deploy_on_push: false`. Un push ne déclenche aucun déploiement.

Propriétés confrontées à la [référence App Spec](https://docs.digitalocean.com/products/app-platform/reference/app-spec/). La [liste officielle des régions App Platform](https://docs.digitalocean.com/reference/pydo/reference/apps/list_regions/) associe `lon` à `lon1`. La spécification n’invente ni identifiant VPC, ni hostname de base, ni domaine.

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

Le proxy ne peut fournir que l’adresse client, le protocole et le port via les en-têtes X-Forwarded. X-Forwarded-Host et X-Forwarded-Prefix sont ignorés. Cette configuration suppose l’entrée par le proxy App Platform, sans accès direct public au conteneur et sans Cloudflare.

Créer **manuellement** une clé Laravel de 32 octets aléatoires, encodée en base64 avec son préfixe Laravel. Utiliser un terminal privé ou un gestionnaire de secrets ; ne jamais la générer dans un journal CI, la copier dans une PR ou l’inscrire dans un fichier suivi. Ajouter `APP_KEY` uniquement sur **`web`**, activer **Encrypt / SECRET** et choisir **Run Time**. Les jobs SQL n’ont pas besoin de la clé et ne la reçoivent pas. Conserver une copie dans un gestionnaire de secrets ; conserver la même clé pendant les redéploiements et rollbacks.

Le démarrage web refuse une clé absente ou invalide avant la compilation de configuration. Aucun `key:generate` n’est exécuté dans le build ou dans un job. Sources : [variables runtime, chiffrement et bindables](https://docs.digitalocean.com/products/app-platform/how-to/use-environment-variables/).

## Contrôles avant création et validation du coût

1. Vérifier que cette PR est fusionnée et que toutes les CI de `main` sont vertes ; noter le commit à déployer.
2. Importer `.do/app.yaml` dans le parcours de création App Platform. Autoriser l’application GitHub DigitalOcean uniquement pour le dépôt privé concerné.
3. Vérifier Londres/LON1, une seule instance web à 5 USD/mois, la base **de développement** à 7 USD/mois, les deux jobs minimaux et aucun composant/domaine supplémentaire.
4. Sélectionner l’association au VPC existant approprié de LON1 et vérifier que l’application et la base partagent ce réseau. `DATABASE_PRIVATE_URL` n’est disponible que dans ce cas. Ne pas remplacer le bindable par une URL publique ou une valeur copiée. Si l’import demande un identifiant VPC, le sélectionner dans le panneau puis conserver cette valeur hors du modèle versionné.
5. Ajouter le secret `APP_KEY` au service web avant de confirmer la création. Vérifier les portées Run Time, la liaison privée de chaque composant et la désactivation de l’autodeploy.
6. Lire puis approuver personnellement le récapitulatif du coût. La validation hors ligne ne vérifie ni les permissions GitHub, ni l’association VPC, ni les disponibilités de l’équipe ni son devis.

Sources : [création d’application](https://docs.digitalocean.com/products/app-platform/how-to/create-apps/), [VPC App Platform](https://docs.digitalocean.com/products/app-platform/how-to/enable-vpc/). Les commandes `doctl apps create` et `doctl apps update` sont des opérations réelles : elles ne font partie d’aucune CI de ce dépôt et ne sont pas exécutées par cette préparation.

## Build et premier déploiement

L’image multi-stage utilise des images officielles épinglées par digest pour PHP 8.3/Apache, Node 24.19.0 et Composer 2.10.3. npm 11.17.0 suit `packageManager`. `.do/build-production.sh` installe les dépendances verrouillées, vérifie les versions, effectue les audits et construit Vite. Les paquets Debian proviennent de leurs dépôts de sécurité ; mettre à jour et retester les digests périodiquement plutôt que désactiver un audit.

La partie PHP installe sans dépendances de développement et prépare les caches de routes, événements et vues. **Elle supprime le cache de configuration et ne le reconstruit pas.** Celui-ci est créé seulement au démarrage web, après validation des variables runtime et de `APP_KEY`. Les assets prennent également en compte les vues de pagination Laravel. Aucun `.env`, SQLite, certificat, `vendor` local, `node_modules` local ou cache local n’entre dans le contexte Docker. Source : [construction Dockerfile App Platform](https://docs.digitalocean.com/products/app-platform/reference/dockerfile/).

Lors du premier déploiement manuel, le job `PRE_DEPLOY` exécute successivement :

```sh
php artisan deskops:production-check --database --no-interaction
php artisan migrate --force --no-interaction
php artisan deskops:demo-reset --force --exclusive --no-interaction
php artisan deskops:production-check --ready --no-interaction
```

Le script s’arrête au premier échec. Le contrôle avant migration refuse des comptes ou tickets non-démo, y compris une ancienne base sans marqueurs. Le reset revérifie ce périmètre **dans sa transaction** ; il ne revendique aucun compte existant. Une base vide dédiée est acceptée. Aucun `migrate:fresh`, `migrate:reset`, rollback forcé ou contournement des triggers n’est utilisé. L’échec d’un [job avant déploiement bloque la publication](https://docs.digitalocean.com/products/app-platform/getting-started/migrate-from-heroku/).

Résultat initial attendu : 3 comptes explicitement démo, 12 tickets, 24 commentaires et 60 activités. Les tables `sessions`, `cache` et `cache_locks` existent déjà dans les migrations Laravel ; aucune migration en double n’est ajoutée. Le web exécute le contrôle `--web`, compile la configuration runtime, puis démarre Apache sur `public/` en port 8080.

## Contrôles après déploiement et logs

1. Consulter Activity : builds réussis, `prepare-demo` réussi, service prêt. Consulter les logs de chaque composant via le panneau, sans afficher ses variables d’environnement.
2. Vérifier `GET /health` sur l’URL App Platform : HTTP **204**, corps vide, aucune session ni requête PostgreSQL. C’est un contrôle du serveur HTTP, pas un diagnostic de disponibilité de la base.
3. Ouvrir la connexion et utiliser les deux boutons d’exploration. Vérifier rôle administrateur/agent, bandeau fictif, navigation et cookies Secure/HTTP-only/SameSite=lax. Aucun mot de passe à chercher ou publier.
4. Vérifier l’absence d’inscription publique, récupération de mot de passe, route de reset ou outil de maintenance web. Les jobs et commandes Artisan ne sont pas routables.
5. Pour un contrôle console sans divulgation :

```sh
php artisan deskops:production-check --web --database --no-interaction
```

`--ready` vérifie les nombres exacts de la fixture et s’utilise juste après un reset. Pendant l’exploration, des tickets fictifs supplémentaires sont normaux. Les logs Laravel vont vers stderr et retirent les URL de connexion des messages, contextes et exceptions. Apache ne journalise ni URL, ni query string, ni cookie, ni en-tête d’authentification. Ne jamais utiliser `env`, `config:show database`, `config:show app.key`, un dump ou un mode trace pour vérifier la connexion. Source : [logs et déploiements](https://docs.digitalocean.com/products/app-platform/how-to/manage-deployments/).

L’URL PostgreSQL injectée demeure la source de vérité. Conserver son `sslmode`, généralement `require` pour cette base de démonstration ; ne pas imposer `verify-full` sans certificat racine ou magasin de confiance approprié. La CI utilise seulement `sslmode=disable` sur son service PostgreSQL éphémère sans accès externe.

## Reset quotidien

Chaque jour à 04:00 Europe/Paris, `reset-demo` contrôle la base dédiée, appelle `deskops:demo-reset --force --exclusive`, puis vérifie la fixture. Le verrou database partagé expire après 300 secondes et se libère aussi après exception. La réservation SQL évite le chevauchement avec les mutations publiques. Un échec provoque un rollback complet, un statut de job en échec et des sorties générales sans connexion affichée.

Les ajouts démo sont supprimés et les lignes initiales restaurées avec leurs identifiants et dates fixes. Les sessions démo sont révoquées, y compris les sessions anciennes encore présentes en mémoire grâce au compteur de génération. Un visiteur doit se reconnecter après le reset ; son ancienne requête ne peut modifier la fixture reconstruite. Les hashes de mots de passe ne sont ni affichés ni renouvelés. Deux exécutions rendent les mêmes données métier. Le job refuse toute base contenant des comptes ou tickets non-démo ; le reset général conserve également ses protections de relations croisées.

## Rollback et destruction

Pour revenir en arrière, sélectionner un déploiement réussi dans Activity et utiliser le rollback de code App Platform. Vérifier la compatibilité de cet ancien code avec le schéma courant avant de confirmer. Un rollback de conteneur ne restaure pas les données et ne doit pas lancer automatiquement `migrate:rollback`. Les migrations restent compatibles avec l’ancienne application tant qu’un retour est nécessaire. Conserver `APP_KEY` et vérifier à nouveau santé et connexions.

Si un problème vient des variables ou du job, corriger la configuration puis lancer un déploiement manuel. Ne pas effacer la base pour contourner une erreur. Consulter l’état des jobs après le rollback et vérifier que le planning correspond à la version retenue.

Pour arrêter définitivement la démonstration : supprimer l’application dans le panneau après décision explicite, vérifier la suppression de **tous** ses composants et de la base de développement associée, puis vérifier qu’aucun cluster/base, job, réseau payant, IP dédiée ou ressource orpheline ne reste facturé. Vérifier le tableau de bord Databases et la facturation. Révoquer l’autorisation GitHub devenue inutile si elle n’est partagée avec aucune autre application. Ne supprimer un VPC partagé avec aucun contrôle préalable. Aucun domaine/DNS/Cloudflare n’a été créé ici. Source : [destruction d’applications](https://docs.digitalocean.com/products/app-platform/how-to/destroy-app/).

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

`--schema-only` ne résout pas les ressources du compte et ne crée rien. Le validateur officiel 1.185.0 est téléchargé avec contrôle SHA-256 en CI, sans authentification. Les tests structurels parsèment aussi le YAML avec Symfony YAML déjà installé ; aucune dépendance n’est ajoutée.

Les suites SQLite et PostgreSQL 18 tournent en ordre normal et aléatoire, avec les caches Laravel. Une troisième CI construit réellement l’image, applique les migrations sur PostgreSQL sous un rôle non superutilisateur, exécute les jobs sans `APP_KEY`, démarre Apache avec une clé éphémère runtime, teste les deux connexions et les cookies, crée un ticket fictif puis effectue deux resets. Elle vérifie les sessions révoquées et le 204 même pendant une interruption de PostgreSQL. Aucune image n’est publiée dans un registre.

La machine locale dispose de PHP et Node mais pas de Docker/PostgreSQL. Le script de build est également exécuté dans une copie isolée sans `.env`, sans toucher à la base ou aux dépendances habituelles ; la construction du conteneur et le scénario PostgreSQL sont validés sur les runners éphémères GitHub, sans installation de service local.
