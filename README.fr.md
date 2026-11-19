# ShipConnect for WooCommerce

**by Acode**

Connectez vos transporteurs à WooCommerce : **tarifs réels au checkout**, **envoi au transporteur en un clic ou automatiquement après confirmation**, **suivi automatique** des colis, **tableaux de bord, graphiques et alertes**, depuis un seul panneau. Interface en **anglais et en français** (suit la langue de WordPress).

> Version 0.2.0. Les connexions aux API ont été écrites d'après leur documentation publique, mais **pas encore testées contre des comptes réels** (voir [Limites connues](#limites-connues)).

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Mise en route](#mise-en-route)
- [Confirmer puis envoyer une commande](#confirmer-puis-envoyer-une-commande)
- [Tableau de bord, analyses et alertes](#tableau-de-bord-analyses-et-alertes)
- [Transporteurs disponibles](#transporteurs-disponibles)
- [Catalogue mondial, filtre par pays et logos](#catalogue-mondial-filtre-par-pays-et-logos)
- [Suivi automatique et webhooks](#suivi-automatique-et-webhooks)
- [Sécurité](#sécurité)
- [Langues](#langues)
- [Architecture](#architecture)
- [Ajouter un transporteur](#ajouter-un-transporteur)
- [Limites connues](#limites-connues)
- [Feuille de route](#feuille-de-route)

---

## Fonctionnalités

| Besoin | Ce que fait le plugin |
|---|---|
| Afficher les prix de livraison | Méthode d'expédition « ShipConnect » : tarifs de vos transporteurs au panier, marge, frais fixes et livraison offerte configurables, tarif de secours. |
| Confirmer avant d'expédier | Statut de commande **« Confirmée »** ; les commandes en paiement à la livraison sont mises en attente jusqu'à votre confirmation (appel téléphonique). |
| Envoyer les commandes | Bouton **« Envoyer au transporteur »** dans la liste des commandes, le tableau de bord et la fiche commande ; action groupée ; envoi automatique quand la commande atteint le statut choisi (par défaut « Confirmée »). |
| Vérifier le prix avant d'acheter | Pour un transporteur par API, les tarifs de la commande s'affichent et vous choisissez le service avant la création de l'envoi. |
| Corriger ou annuler | Correction du statut et du numéro de suivi, annulation de l'envoi (aussi chez le transporteur quand il le permet, par ex. Sendit), puis nouvel envoi possible. |
| Suivre les colis | Synchronisation toutes les 30 minutes **et** webhooks : statut, note dans la commande, statut WooCommerce mis à jour (livré → « Terminée », retour → statut au choix). |
| Piloter l'activité | Tableau de bord (alertes, chiffres clés, commandes à envoyer, derniers envois) et onglet **Analyses** (taux de livraison, délai moyen, retours, paiement à la livraison en cours, graphiques par jour, statut et transporteur). |
| Être prévenu | Alertes (échecs d'envoi, colis en anomalie ou sans nouvelles, retours, commandes en attente, tâche planifiée en retard, configuration incomplète), pastille dans le menu, e-mail en cas d'anomalie. |
| Informer le client | Encadré « Suivre mon colis », numéro de suivi dans les e-mails, message de livraison et progression vers la livraison offerte au panier. |
| Transporteurs sans API | Mode « suivi manuel » : numéro saisi à la main, lien de suivi généré, tarif forfaitaire optionnel. |
| Choisir un transporteur | Catalogue mondial filtrable par **pays**, région et type d'API, avec les **logos** des sociétés. |

Le panneau se trouve dans **WooCommerce > Livraison ShipConnect** : *Tableau de bord*, *Analyses*, *Transporteurs*, *Réglages*, *État du système*, *Aide & suivi*.

## Prérequis

- WordPress 6.4 ou plus récent
- WooCommerce 8.0 ou plus récent (compatible avec le stockage des commandes HPOS)
- PHP 7.4 ou plus récent, avec l'extension **sodium** (recommandé) ou **OpenSSL** pour chiffrer les clés d'API
- Accès sortant HTTPS vers les API des transporteurs

## Installation

1. Copiez le dossier `shipconnect-for-woocommerce` dans `wp-content/plugins/`, ou compressez-le en ZIP et importez-le dans **Extensions > Ajouter**.
2. Activez **ShipConnect for WooCommerce**.
3. Ouvrez **WooCommerce > Livraison ShipConnect** : la liste « Mise en route » du tableau de bord indique les étapes restantes.

## Mise en route

1. **Connecter un transporteur** : onglet *Transporteurs*, puis *Connecter* (Sendit, Shippo, EasyPost, DHL Express, Aramex) ou *Ajouter* un transporteur sans API. Le plugin teste la clé avant d'enregistrer.
2. **Ajouter la méthode aux zones d'expédition** : *WooCommerce > Réglages > Livraison*, ajoutez « ShipConnect » à votre zone.
3. **Compléter l'adresse expéditeur** (*Réglages*, section 1) : téléphone, e-mail et adresse sont demandés par les transporteurs.
4. **Régler la confirmation et l'envoi** (*Réglages*, section 2) : statut « Confirmée », mise en attente du paiement à la livraison, envoi automatique.
5. **Tester une commande** : passez une commande test, confirmez-la, puis vérifiez l'envoi, l'étiquette et le suivi.

## Confirmer puis envoyer une commande

Déroulé type (paiement à la livraison) :

1. Le client commande : la commande arrive **en attente**.
2. Vous l'appelez pour confirmer, puis vous passez la commande à **« Confirmée »** (dans la commande, ou en groupe depuis la liste avec l'action « Changer le statut en confirmée »).
3. ShipConnect l'envoie au transporteur **automatiquement**. Vous pouvez aussi cliquer sur **« Envoyer au transporteur »** dans la colonne *Livraison* de la liste des commandes, quel que soit le statut.
4. Selon le transporteur :
   - **par API** : les tarifs de la commande s'affichent, vous choisissez le service et confirmez ; l'envoi est créé et l'étiquette récupérée ;
   - **sans API** : vous saisissez le numéro de suivi donné par le transporteur.
5. La colonne *Livraison* affiche le statut et le numéro de suivi, mis à jour sans recharger la page.

L'envoi automatique utilise le tarif choisi au checkout, ou votre premier transporteur par API pour les commandes passées avec une autre méthode (forfait, livraison gratuite…). Un verrou par commande empêche les doublons (clic, tâche planifiée et action groupée en même temps). L'action groupée « Envoyer au transporteur » met les commandes en file d'attente (Action Scheduler) : la page ne bloque jamais.

## Tableau de bord, analyses et alertes

- **Tableau de bord** : liste de mise en route, alertes classées par gravité (avec icône et libellé), chiffres clés (commandes à envoyer, colis en cours, anomalies, paiement à la livraison en cours), nouveautés masquables, commandes à envoyer, derniers envois.
- **Analyses** (7, 30 ou 90 jours) : taux de livraison (chiffre principal), envois créés, délai moyen, taux de retour, anomalies, montant à encaisser ; graphique des envois par jour, colis par statut, envois par transporteur, tableau de performance par transporteur. Chaque graphique a son tableau de données et ses infobulles au survol et au clavier.
- **Alertes** : pastille dans le menu, avis sur la liste des commandes pour les alertes critiques, e-mail quand un colis passe en anomalie (adresse configurable).
- **État du système** : versions, chiffrement, protection du dossier des étiquettes, tâches planifiées, file d'attente, stockage des commandes, table des envois, zones de livraison, régénération des adresses de webhook.

## Transporteurs disponibles

### Connexions API

| Connexion | Type | Capacités | Identifiants |
|---|---|---|---|
| **Sendit** (Maroc) | Transporteur national | Tarifs par ville, création d'envoi avec paiement à la livraison, étiquette, suivi (synchronisation et webhook), annulation | Clé publique, clé secrète, ID de la ville de ramassage |
| **Quick Livraison** (Maroc) | Transporteur national | Tarifs par ville, envoi avec paiement à la livraison, étiquette PDF, suivi (synchronisation et webhook), annulation | Clé API |
| **Mylerz** (Maroc) | Transporteur national | Envoi avec paiement à la livraison, lettre de transport (AWB), suivi, annulation ; tarifs en direct si l'entrepôt est renseigné | Utilisateur et mot de passe du compte d'intégration, nom de l'entrepôt |
| **Colivry** (Maroc) | Transporteur national | Envoi avec paiement à la livraison, suivi (synchronisation et webhook), annulation ; pas d'étiquette via l'API | Clé publique, clé privée, clé de boutique facultative |
| **Atlas Livraison** (Maroc) | Transporteur national | Tarifs par ville, envoi avec paiement à la livraison, suivi (synchronisation et webhook) ; pas d'étiquette ni d'annulation via l'API publique | Clé API, ID de boutique facultatif |
| **Shippo** | Agrégateur multi-transporteurs (USPS, UPS, DHL Express, FedEx…) | Tarifs, étiquettes, suivi, webhook | Jeton API (`shippo_test_…` pour commencer) |
| **EasyPost** | Agrégateur multi-transporteurs | Tarifs, étiquettes, suivi, webhook | Clé API (`EZTK…` de test, `EZAK…` en production) |
| **DHL Express** | Envois internationaux (API MyDHL 3.4.1) | Tarifs, création d'envoi, étiquette, suivi (synchronisation, pas de webhook) | Clé API, secret, numéro de compte, environnement |
| **Aramex** | ShippingAPI (JSON) | Création d'envoi et étiquette ; tarifs forfaitaires ; suivi pas encore branché | Utilisateur, mot de passe, compte, PIN, entité, pays, environnement |
| **Transporteur sans API** | Saisie manuelle | Suivi manuel, lien de suivi, tarif forfaitaire optionnel | Nom, modèle de lien (`{tracking}` à la place du numéro) |

## Catalogue mondial, filtre par pays et logos

Le fichier `data/carriers-catalog.json` recense **211 sociétés** dans le monde (détail dans [`docs/CATALOGUE.md`](docs/CATALOGUE.md)).

- **Filtre par pays** : par défaut, le catalogue montre les sociétés qui couvrent le pays de la boutique, plus celles qui livrent dans le monde entier (et l'Union européenne pour un pays membre). Le menu *Pays* permet d'en choisir un autre ou d'afficher tous les pays ; recherche, région et type d'API se combinent.
- **Logos** : aucun logo n'est livré avec l'extension. Le bouton **« Charger les logos »** télécharge une seule fois l'icône officielle de chaque société depuis son propre site, la vérifie (vraie image, taille limitée, SVG refusé) et la stocke dans `wp-content/uploads/shipconnect-logos/`. Le navigateur de l'administrateur n'appelle donc aucun site tiers. Sans logo, des initiales colorées s'affichent. Les logos restent la propriété de leurs détenteurs.
- Les informations du catalogue viennent d'une recherche documentaire : elles sont **indicatives** et à vérifier avec le transporteur avant tout contrat.

État de la recherche (211 fiches, dont 103 ajoutées en version 0.2 et vérifiées sur les sources officielles : page de documentation chargée, endpoint qui répond) :

- **119** ont une documentation API publique, **59** donnent leur API sur demande, **1** n'a pas d'API, **32** sont à vérifier.
- **82** fiches ont une confiance faible : à valider avant toute mise en production.
- Maroc : **Sendit, Quick Livraison, Mylerz, Colivry et Atlas Livraison** sont connectables directement. ASAP Delivery et Eagle Express ont une API publique, mais leurs clés sont dérivées de l'e-mail et du téléphone du compte (devinables) : pas de connecteur pour l'instant. Pour les autres (Amana, Ozon Express, Forcelog, CTM…), utilisez le suivi manuel en attendant un accès commercial.
- Le site officiel de 73 fiches existantes a été ajouté pour les logos ; les autres sont déduits de l'adresse de leur documentation.

## Suivi automatique et webhooks

- **Tâche planifiée** (toutes les 30 minutes) : interroge par lots de 25 les envois non finalisés de moins de 45 jours, en commençant par ceux vérifiés le moins récemment.
- **Webhook** : le transporteur signale un changement ; le plugin vérifie alors le statut auprès du transporteur au lieu de se fier au contenu reçu.

Chaque connexion a sa propre adresse de webhook avec une clé secrète (onglets *Aide & suivi* et *État du système*). Le bouton « Générer de nouvelles adresses de webhook » invalide immédiatement les anciennes.

## Sécurité

- Clés d'API chiffrées avec **libsodium** (XSalsa20-Poly1305), migration automatique des anciens formats ; clé dédiée possible avec la constante `SHIPC_ENCRYPTION_KEY` dans `wp-config.php`.
- Toutes les actions du panneau vérifient un jeton (nonce) et la capacité `manage_woocommerce` ; les entrées sont nettoyées et les sorties échappées.
- Une connexion manuelle ne peut pas écraser une connexion API ; les listes n'acceptent que leurs propres valeurs ; les liens doivent être en `http(s)`.
- Étiquettes : seuls PDF, PNG et ZPL sont acceptés (signature vérifiée), stockés dans un dossier privé avec un nom aléatoire et servis uniquement aux gestionnaires WooCommerce.
- Logos : téléchargés côté serveur avec `wp_safe_remote_get` (adresses internes refusées), types et tailles contrôlés, exécution de scripts interdite dans le dossier.
- Désinstallation : réglages, connexions, table, files d'attente, caches, étiquettes, logos et métadonnées `_shipc_*` sont supprimés.

## Langues

Les textes source sont en anglais, avec une traduction française complète (`languages/shipconnect-for-woocommerce-fr_FR.po` / `.mo`, modèle `languages/shipconnect-for-woocommerce.pot`). Le domaine de traduction est `shipconnect-for-woocommerce` (égal au slug WordPress.org). Le plugin suit la langue du site ou celle du profil de l'administrateur. Une fois le plugin publié sur WordPress.org, les traductions se font sur translate.wordpress.org (les paquets de langue y priment sur les fichiers fournis) ; sinon, traduisez le fichier `.pot` (Poedit, Loco Translate).

## Architecture

```
shipconnect-for-woocommerce/
├── shipconnect-for-woocommerce.php      Bootstrap, autoload, compatibilité HPOS
├── uninstall.php                        Nettoyage complet à la suppression
├── includes/
│   ├── class-shipc-plugin.php           Point d'entrée, enregistrement des modules
│   ├── class-shipc-installer.php        Activation, table des envois, mises à jour du schéma
│   ├── class-shipc-settings.php         Réglages (option unique)
│   ├── class-shipc-connections.php      Connexions transporteurs enregistrées
│   ├── class-shipc-crypto.php           Chiffrement libsodium des identifiants
│   ├── class-shipc-http.php             Client HTTP JSON
│   ├── class-shipc-logger.php           Journal WooCommerce (source « shipconnect »)
│   ├── class-shipc-status.php           Statuts normalisés des colis
│   ├── class-shipc-catalog.php          Catalogue JSON, pays, régions
│   ├── class-shipc-logos.php            Logos des transporteurs (téléchargement et monogrammes)
│   ├── class-shipc-carrier-interface.php / class-shipc-carrier-base.php
│   ├── carriers/                        Sendit, Shippo, EasyPost, DHL Express, Aramex, manuel
│   ├── class-shipc-shipment-data.php    Expéditeur, destinataire, colis
│   ├── class-shipc-rates.php            Tarifs multi-transporteurs, marge, cache
│   ├── class-shipc-shipping-method.php  Méthode d'expédition WooCommerce
│   ├── class-shipc-shipments-repo.php   Table {prefix}shipc_shipments
│   ├── class-shipc-shipment-service.php Création (verrou anti-doublon), statuts, correction, annulation
│   ├── class-shipc-tracking.php         Tâche planifiée et webhook REST
│   ├── class-shipc-order-integration.php Envoi automatique, encadré commande, suivi client, e-mails
│   ├── class-shipc-order-workflow.php   Statut « Confirmée », colonne et bouton, action groupée
│   ├── class-shipc-labels.php           Étiquettes privées
│   ├── class-shipc-stats.php            Statistiques
│   ├── class-shipc-charts.php           Graphiques SVG et barres (sans bibliothèque)
│   ├── class-shipc-alerts.php           Alertes, pastille, e-mail d'anomalie
│   ├── class-shipc-announcements.php    Nouveautés et messages client
│   └── class-shipc-admin.php            Menu, onglets, actions AJAX, ressources
├── admin/
│   ├── views/                           Onglets du panneau et encadré commande
│   ├── css/admin.css, css/catalog.css
│   └── js/admin.js, js/catalog.js       Dialogues, envoi, infobulles, filtres, logos
├── languages/                           .pot, .po et .mo (français)
├── public/css/shipconnect-public.css    Encadré de suivi côté client
└── data/carriers-catalog.json           Catalogue mondial
```

## Ajouter un transporteur

Un adaptateur est une classe qui étend `ShipC_Carrier_Base` dans `includes/carriers/` (`ShipC_Carrier_Xyz → class-shipc-carrier-xyz.php`).

```php
class ShipC_Carrier_Xyz extends ShipC_Carrier_Base {

	public static function adapter_id()   { return 'xyz'; }
	public static function adapter_name() { return 'Xyz Express'; }
	public static function docs_url()     { return 'https://docs.xyz.example/'; }

	public static function capabilities() {
		return array( 'rates', 'create_shipment', 'label', 'tracking_sync', 'cod' );
	}

	public static function fields() {
		return array(
			array( 'key' => 'api_key', 'label' => __( 'API key', 'shipconnect-for-woocommerce' ), 'type' => 'password', 'secret' => true, 'required' => true ),
		);
	}

	public function test()                                   { /* appel léger, array|WP_Error */ }
	public function get_rates( array $shipment )             { /* array[]|WP_Error */ }
	public function create( array $shipment, $service_code ) { /* tracking_number, label_url… */ }
	public function track( array $shipment )                 { /* status (ShipC_Status), events */ }
	public function cancel_remote( array $shipment )         { /* true|WP_Error|null (facultatif) */ }
}
```

Ajoutez la classe dans `ShipC_Connections::adapters()` : le formulaire de connexion se génère à partir de `fields()`. Le slug du catalogue doit correspondre à `adapter_id()` pour que la carte devienne « Connecter » et affiche le bon logo.

## Limites connues

- **Non testé contre des comptes réels** (Sendit, Shippo, EasyPost, DHL Express, Aramex). Les flux ont été vérifiés sur un site WordPress local avec un transporteur manuel ; testez chaque API avec des clés de test avant la production.
- **Sendit** : l'ID de la ville de ramassage doit être renseigné ; la correspondance des villes de livraison se fait par nom (zones Sendit) ; le webhook doit être configuré dans l'espace Sendit.
- **International et douane** : déclaration générée pour DHL Express uniquement ; pour Shippo et EasyPost, limitez-vous aux envois domestiques.
- **Aramex** : tarifs en direct et suivi pas encore intégrés ; contre-remboursement non géré.
- **Un colis par commande** ; **taux de change** manuel ; **checkout par blocs** non vérifié.
- **Action groupée** : elle utilise le tarif choisi au checkout ou le moins cher, sans écran de confirmation du prix (contrairement au bouton par commande).
- **Logos** : certains sites bloquent les requêtes automatiques ou n'exposent qu'une petite icône ; ces sociétés gardent leurs initiales.
- **Catalogue** : notes en français (données de recherche) ; les fiches à confiance faible doivent être vérifiées.
- **Webhook** : la clé est dans l'URL et peut apparaître dans les journaux du serveur.
- **Données personnelles** : nom, adresse, téléphone et e-mail du client sont transmis au transporteur choisi. Mentionnez-le dans votre politique de confidentialité.
- **Nginx** : le fichier `.htaccess` du dossier des étiquettes n'a pas d'effet ; ajoutez une règle qui interdit `wp-content/uploads/shipconnect-private/`.

## Feuille de route

1. Valider Sendit, Shippo, EasyPost et DHL Express sur comptes de test (envoi, étiquette, suivi, webhook, annulation).
2. Suivi Aramex et tarifs en direct.
3. Agrégateurs de suivi (17TRACK, AfterShip, Ship24) pour les transporteurs sans API.
4. Mise à jour groupée des statuts par fichier CSV, résumé quotidien par e-mail.
5. Déclarations douanières, multi-colis, impression groupée d'étiquettes.
6. Compatibilité checkout par blocs, multi-devises.
