# ETHAN — Architecture

> Suivi des affaires d'un sous-traitant minier du Grand Katanga, du RFQ au paiement.
> Édité par **BELEJ-CONSULTING**. Stack et système de design hérités de PerfectMaint.

---

## 1. Métier

### 1.1 Le processus suivi

```
Client minier ──RFQ──► Nous ──demandes de prix──► Fournisseurs
                        │ ◄──────── offres (ZAR, USD, EUR…) ─┘
                        ├──quotation (USD)──► Client
Client ──PO client──►   │
                        ├──PO fournisseur──► Fournisseur retenu
                        │   expédition (transitaire / fournisseur) ──► livraison directe chez le client
                        │   une expédition peut regrouper plusieurs PO
                        ├── POD ─► facture client ─► paiements reçus ─► relevé client
                        └── facture fournisseur ─► paiements effectués ─► relevé fournisseur
```

**Le fil conducteur est l'affaire, ouverte par la RFQ.** Chaque ligne garde le lien avec sa ligne d'origine
(ligne RFQ → offre fournisseur → ligne de quotation → ligne de PO client → ligne de PO fournisseur → expédition → POD → facture).
C'est ce qui permet de suivre, ligne par ligne, ce qui est commandé, livré, facturé et payé, ainsi que la marge réelle.

### 1.2 Décisions validées

| Sujet | Décision |
|---|---|
| Utilisateurs | Une seule société (pas de multi-sociétés). |
| Devises | USD = monnaie de base. Offres et PO fournisseurs possibles en ZAR, EUR, CNY, ZMW, CDF… convertis au dernier taux saisi. |
| Transport | Toujours par un transitaire ou par le fournisseur (pas de flotte propre). |
| TVA | 16 % par défaut, désactivable par client (exonération) et par quotation / facture. |
| Facturation | Une livraison partielle se facture dès sa POD. |
| Validation des marges | Désactivée par défaut ; activable avec un seuil (Paramètres → Société). |

### 1.3 Rôles

| Rôle | Périmètre |
|---|---|
| `admin` | Tout, y compris utilisateurs et paramètres société. |
| `manager` (direction) | Tout le métier ; **approuve** les quotations sous le seuil de marge. |
| `sales` | RFQ, quotations, clients, sourcing. |
| `purchasing` | Sourcing, fournisseurs, (PO fournisseurs). |
| `logistics` | (Expéditions, POD.) |
| `finance` | Taux de change, (factures, paiements, relevés). |
| `viewer` | Lecture seule. |

Matrice complète : [config/permissions.php](../config/permissions.php). Chaque route vérifie sa permission côté serveur (403).

---

## 2. Stack

Reprise de PerfectMaint : PHP ≥ 8.2 sans framework (noyau `app/Core`), MariaDB via PDO, migrations SQL numérotées,
PHPMailer, PHPUnit ; jQuery, DataTables, SweetAlert2, Lucide, Chart.js, ExcelJS / pdfmake chargés à la demande ;
IBM Plex Sans ; tout est auto-hébergé, sans CDN en production. Le JavaScript pur est testé avec `node:test`.

| Écart avec PerfectMaint | Raison |
|---|---|
| Couleurs d'état nommées par **tonalité** (`ok`, `progress`, `warn`, `critical`, `neutral`) | Chaque statut métier se projette sur une tonalité (`App\Domain\Shared\Tone`). |
| Accent `#0E4D64` (provisoire) | À remplacer par la couleur de marque. |
| Verrou SHA-256 des bibliothèques front (`bin/vendor-assets.lock`) | Une bibliothèque modifiée à la source fait échouer l'import. |
| Module de calcul JS `eth-pricing.js` testé contre les **mêmes valeurs** que le PHP (`tests/pricing-vectors.json`) | Le prix affiché pendant la saisie est exactement celui que le serveur enregistre. |

---

## 3. Architecture applicative

```
public/index.php ─► App (session → langue → Router → erreurs → en-têtes de sécurité)
   Router : authentification, permission (403), CSRF sur toute méthode non-GET (419)
   Controllers ──► Services (transactions + ActivityLogger + numérotation)
                      └─► Repositories (SQL préparé)
                      └─► Domain (règles pures : statuts, prix, marges, change, comparaison)
   Views (gabarits PHP, e(), helpers de formulaire)
```

```
app/
  Core/          Router, Container, Request/Response, Session, Csrf, I18n, View, Validator, Database, Migrator, Logger,
                 helpers.php, form_helpers.php
  Security/      Auth, CurrentUser, Role, Permissions, LoginThrottle, SecurityHeaders
  Domain/
    Shared/      Money (centimes), Milli (quantités), Fx (change), Reference, Tone, RuleViolation
    Sales/       RfqStatus, QuotationStatus, Pricing, MarginApproval
    Sourcing/    SupplierQuoteStatus, OfferComparison
    Orders/      ClientOrderStatus, SupplierOrderStatus, Coverage
    Logistics/   ShipmentStatus
    Delivery/    EventChain (journal scellé des jalons et POD)
    Invoicing/   InvoiceStatus, Receivables (balance âgée, imputation), CreditNoteRules (plafonds des avoirs)
    Purchasing/  SupplierInvoiceStatus, ThreeWayMatch
    Finance/     RealCost (coût USD au taux payé), RealMargin (marge réelle par ligne), LandedCost (répartition des frais),
                 CashForecast (trésorerie par semaine, DSO / DPO)
  Controllers/   Controller (base), MasterDataController (référentiel), Rfq, Sourcing, Quotation, Settings…
  Services/      RfqService, SourcingService, QuotationService, MasterDataService, Settings, Sequences,
                 AlertService, ActivityLogger
  Repositories/  MasterRepository (+ Client, Supplier, Item), Rfq, SupplierQuote, Quotation, Currency, User
  Views/         layouts (app, minimal, print), partials, un dossier par écran
config/          app.php (.env), routes.php, permissions.php, menu.php
database/migrations/  0001 à 0008 (voir § 4)
```

**Recette d'un nouvel écran :**
1. Ajouter la route (avec sa permission) dans `config/routes.php`, et l'entrée de menu si besoin.
2. Écrire la requête SQL dans un repository.
3. Placer la règle métier dans `app/Domain`, sous forme de code pur et testé.
4. Faire l'écriture dans un service : transaction et `ActivityLogger::log()`.
5. Une erreur métier lève `RuleViolation('clé.i18n')` ; le contrôleur l'affiche en message flash.
6. Ajouter les libellés FR et EN ; `I18nKeysTest` vérifie qu'ils existent.

---

## 4. Données

| Migration | Tables |
|---|---|
| `0001_core` | `users`, `login_attempts`, `activity_log`, `sequences`, `files`, `settings` |
| `0002_reference_data` | `currencies` (USD, ZAR, ZMW, EUR, CNY, CDF), `exchange_rates`, `clients`, `suppliers`, `items` |
| `0003_sales_sourcing` | `rfqs`, `rfq_lines`, `supplier_quotes`, `supplier_quote_lines`, `quotations`, `quotation_lines` |
| `0004_orders` | `client_orders`, `client_order_lines`, `supplier_orders`, `supplier_order_lines` |
| `0005_shipments_pod` | `shipments`, `shipment_lines`, `shipment_events` (journal scellé), `pods` |
| `0006_client_order_status_length` | Correctif : largeur de `client_orders.status` (désormais vérifiée par `MigratorTest`) |
| `0007_client_invoicing` | `client_invoices`, `client_invoice_lines`, `client_payments`, `client_payment_allocations` |
| `0008_supplier_invoices` | `supplier_invoices` (écarts du rapprochement en JSON), `supplier_invoice_lines` (prix du PO figé à la saisie) |
| `0009_supplier_payments` | `supplier_payments` (devise et taux du jour du paiement), `supplier_payment_allocations` |
| `0010_logistics_costs` | `supplier_invoices.kind` (`goods` / `logistics`, PO facultatif pour la logistique), `supplier_invoice_cost_lines` (frais par expédition) |
| `0011_credit_notes` | `client_credit_notes`, `client_credit_note_lines` (ligne de facture créditée), `client_credit_allocations` |

Conventions (vérifiées par `MigratorTest`) :
- **Montants** en centimes (`*_minor`), **quantités** en millièmes (`*_milli`), **taux** en millionièmes (`*_micro`), tous en `BIGINT`. Aucun flottant n'est autorisé.
- **Taux de change** exprimé en unités de devise pour 1 USD (ZAR 18,25 → 18 250 000), daté, avec un seul taux par jour.
- **Une ligne de quotation conserve son coût d'origine** : devise, prix fournisseur et taux utilisé. La marge reste ainsi explicable même quand le taux change ensuite.
- **Révision d'une quotation** : on crée une nouvelle ligne (même référence, version + 1) et l'ancienne passe en « remplacée ».
- **Références** sans trou par type et par année : `RFQ-2026-0007`, `QT-2026-0012`, attribuées dans la transaction (`SELECT … FOR UPDATE`).
- **Journal** : `activity_log` enregistre qui, quoi, quand, et la valeur avant → après, dans la même transaction que la modification.

---

## 5. Règles métier

### 5.1 RFQ

```
received ─► sourcing ─► quoted ─► won
     └──────────┴──────────┴─► lost / cancelled  (clôture manuelle avec motif)
```

- **Passages automatiques** : `sourcing` à la première demande de prix, `quoted` à l'envoi d'une quotation, `won` à son acceptation.
- **Modifications** : l'en-tête reste modifiable tant que la RFQ est ouverte. Les lignes sont verrouillées dès qu'une offre ou une quotation les reprend.

### 5.2 Sourcing

- On envoie une demande de prix par fournisseur et par RFQ. Le fournisseur répond par une offre dans sa devise, ou décline.
- **Comparaison** : chaque prix est converti en USD au dernier taux connu. La meilleure offre par ligne est la moins chère ; à prix égal, celle qui a le délai le plus court (`OfferComparison`).

### 5.3 Quotation (toujours en USD)

```
draft ─► [pending_approval ─► approved] ─► sent ─► accepted
                                              ├─► rejected ─► superseded
                                              └─► superseded (nouvelle version)
```

- **Création** : la quotation est préremplie avec la meilleure offre de chaque ligne et la marge par défaut (20 %).
- **Saisie** : sur chaque ligne, on peut choisir une autre offre, ajouter des frais unitaires (transport, transit, douane) et régler la marge ou le prix (le calcul fonctionne dans les deux sens).
- **Calcul de la marge** : elle est exprimée sur le prix de vente, `(prix − coût) / prix`. Le prix calculé depuis une marge est arrondi au centime supérieur, pour ne jamais passer sous la marge visée.
- **TVA** : `16 %` appliquée au total HT, sauf pour un client exonéré ou si elle est décochée sur la quotation.
- **Validation des marges** (si elle est activée) : quand la marge globale est sous le seuil, « Envoyer » passe la quotation en `pending_approval`. Seuls `admin` et `manager` peuvent approuver ; le contrôle est fait côté serveur, pas seulement en masquant le bouton.
- **Document client** imprimable en PDF : il ne contient **jamais** de coût, de fournisseur ni de marge.

### 5.4 Commandes

**PO client**
- Il s'enregistre sur une quotation envoyée ou acceptée ; une quotation encore envoyée passe alors en « acceptée ».
- Il est prérempli depuis la quotation, mais les lignes, quantités et prix restent modifiables : commande partielle, prix renégocié. Tout écart avec la quotation est signalé sur la ligne.
- Un même numéro de PO ne peut être enregistré qu'une fois par client.
- Chaque ligne conserve le coût estimé de la quotation, ce qui donne la marge prévue.

**PO fournisseur**
- Il est rédigé dans la devise du fournisseur.
- « Préparer les PO fournisseurs » crée un brouillon par fournisseur retenu en sourcing, au prix de son offre, pour tout le reste à commander.
- Une ligne sans fournisseur retenu se commande à la main ; le formulaire propose alors le prix de l'offre de ce fournisseur, s'il en a fait une.
- Le taux de change est **figé à l'envoi** : le coût d'achat engagé en USD ne bouge plus ensuite.

**Couverture** (`Coverage`)
- Une ligne de PO client peut être couverte par plusieurs PO fournisseurs, en partie chacun.
- On ne commande **jamais plus** que la quantité du client : le contrôle est fait dans le service, à la création comme à la modification.
- Un PO annulé libère sa quantité.

**Cycles de vie**

```
PO client     : received (à commander) ─► ordering (partiel) ─► ordered ─► closed     (+ cancelled si aucun PO actif)
                piloté automatiquement par la couverture
PO fournisseur: draft ─► sent (taux figé) ─► confirmed (réf. du fournisseur)     (+ cancelled)
```

Le bon de commande imprimé pour le fournisseur ne montre ni prix de vente ni numéro de PO du client. Il indique l'adresse de livraison directe chez le client et demande au fournisseur de rappeler notre référence.

### 5.5 Expéditions et POD

```
planned ─► picked_up ─► in_transit ─► at_border ─► cleared ─► delivered (uniquement par la POD)
   └──► cancelled (avant enlèvement)
```

**Expédition**
- Elle concerne **un client** et regroupe des lignes de **plusieurs PO fournisseurs**, envoyés ou confirmés.
- L'envoi partiel est possible, mais on n'expédie jamais plus que la quantité commandée au fournisseur ; une expédition annulée libère ses quantités.
- Elle est prise en charge par un transitaire ou par le fournisseur, avec une référence de suivi (CMR, LTA, BL, plaque du camion).

**Jalons**
- Ils avancent toujours et ne reculent jamais.
- On peut en sauter, par exemple pour une livraison locale sans frontière.
- Chaque jalon conserve son heure réelle et son heure de saisie : un jalon saisi après coup reste visible.

**POD**
- Elle n'est possible qu'après l'enlèvement.
- Elle exige un **document signé**, photo ou PDF de 10 Mo maximum. Le type est vérifié sur le contenu du fichier (`finfo`), pas sur son extension ; le fichier est stocké hors racine web sous un nom aléatoire, puis servi après contrôle d'accès (images dans un contexte isolé, PDF en téléchargement).
- On y saisit la quantité reçue par ligne, ce qui permet de constater un manquant ou une casse.
- Si l'enregistrement échoue, le fichier déjà déposé est supprimé.

**Journal scellé**
- Les jalons et la POD sont chaînés par HMAC (`EventChain`, clé `APP_KEY`). La POD scellée contient l'empreinte SHA-256 du document et les quantités reçues.
- Toute modification en base est signalée sur la fiche (« Journal altéré ») et par `php bin/verify-journal.php`, qui renvoie le code de sortie 1.
- C'est testé : une quantité reçue passée de 38 à 40 en base est bien détectée.

**Effet sur le PO client**
- Les quantités reçues font passer le PO client en `partially_delivered` ou `delivered`.
- Ces quantités livrées sont la base de la facturation client et du rapprochement fournisseur (5.9).

### 5.6 Aperçu des documents et fichiers joints

Les documents produits par ETHAN (quotation, bon de commande) et les fichiers joints (POD en image ou en PDF) s'ouvrent dans une **fenêtre d'aperçu**, depuis les fiches comme depuis les listes (bouton « œil »).

**Utilisation**
- Il suffit d'ajouter l'attribut `data-preview="image|pdf|document"` sur un lien ; en PHP, les helpers `preview_attrs()` et `partials/preview-button` s'en chargent.
- Le lien garde sa vraie adresse : sans JavaScript, ou avec Ctrl/Cmd+clic, la page s'ouvre normalement.

**Fenêtre**
- L'en-tête affiche le titre, le type et le contexte (client, fournisseur, réceptionnaire).
- Actions : Imprimer / PDF pour les documents, Télécharger pour les fichiers, Taille réelle pour les images, Ouvrir dans un onglet, Fermer.
- Les documents sont affichés en mode intégré (`?embed=1`), c'est-à-dire sans leur barre d'outils.
- Au clavier : Échap ferme la fenêtre, le focus reste à l'intérieur tant qu'elle est ouverte et revient ensuite sur le bouton d'origine.
- Sur mobile, la fenêtre passe en plein écran.

**Sécurité**
- Seuls les documents imprimables et `/files/{id}` peuvent être affichés dans un cadre, et uniquement par ETHAN (`frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`). Toutes les autres pages restent non encadrables (`'none'`, `DENY`).
- Les images sont servies sous CSP `sandbox`. Les PDF sont servis sans aucune ressource autorisée (`default-src 'none'`).
- Le paramètre `?download=1` force le téléchargement.
- Les titres et textes affichés dans la fenêtre sont insérés comme texte brut (`textContent`), jamais interprétés comme du HTML.

### 5.7 Tableaux (DataTables)

Tout tableau `.table` devient une DataTable, à trois exceptions près : les tableaux de saisie (`.lines-table`), les tableaux marqués `data-no-dt` et les documents imprimables.

**Deux modes**
- **Liste** (par défaut) : barre de filtres, tri, pagination de 15 lignes (10 à 100) et compteur « N sur M ».
- **`data-dt="compact"`** : pour les tableaux de fiche (lignes, sourcing, comparaison, PO et expéditions liés) ; tri uniquement.

**Filtres déclaratifs, sur l'en-tête de colonne**

```html
<th data-filter="select">Client</th>                    valeurs distinctes de la colonne
<th data-filter="date">Reçue le</th>                    du / au, sur td[data-order] (AAAA-MM-JJ)
<th data-filter="range" data-filter-scale="100">…</th>  min / max, sur td[data-order] (×100 : saisie en USD, valeur en centimes)
```

- `data-filter-label` remplace le libellé du filtre.
- Sur une cellule, `td[data-filter]` remplace la valeur filtrée (par défaut, la première ligne du texte).
- Sur une cellule, `td[data-order]` donne la valeur brute de tri (date ISO, centimes, points de base) : les dates et les montants se trient sur leur valeur réelle, pas sur le texte affiché.

**Barre de filtres**
- Une recherche globale, qui couvre aussi les 2ᵉ lignes des cellules.
- Les filtres déclarés sur les colonnes.
- Le nombre de filtres actifs, un compteur de résultats et un bouton de réinitialisation.
- Sur mobile, les filtres sont repliés derrière un bouton « Filtres (n) » ; la recherche reste visible.

**Vues**
- Les listes gardent au-dessus une **vue** côté serveur : à traiter, en retard, expirant sous 5 jours…
- Ce sont aussi ces vues qu'ouvrent les liens des alertes et des indicateurs du tableau de bord.

**Comportement**
- Les lignes restent cliquables (`tr.row-link`).
- Les colonnes d'actions sont marquées `th.no-sort`.

### 5.7 bis Tableau de bord finance (`/finance`, `FinanceDashboardService`)

- **Trésorerie prévisionnelle sur 8 semaines**, du lundi au dimanche :
  - entrées : factures clients ouvertes à leur échéance ;
  - sorties : factures fournisseurs et frais logistiques bon à payer, en USD au taux de la facture ;
  - l'échu est regroupé en tête ; les factures en écart sont signalées mais non comptées.
  - La trésorerie disponible se saisit sur la page (pas de module bancaire) et sert de point de départ au solde cumulé.
- **Chiffre d'affaires HT et marge réelle par mois** sur 12 mois, par mois d'émission, net des avoirs. Le coût réel de chaque ligne (`MarginService::compute`) suit la quantité facturée dans le mois.
- **Classements** : 5 meilleurs clients (chiffre d'affaires, marge) et 5 principaux fournisseurs (achats et frais logistiques HT en USD) sur 12 mois.
- **Indicateurs** : délai moyen d'encaissement (DSO) et de paiement (DPO) sur 90 jours, taux de devis gagnés sur 12 mois (acceptés ÷ tranchés).
- **Graphiques** : Chart.js, avec une palette catégorielle validée (jetons `--series-1..3`, ordre fixe) et un seul axe en USD. Une info-bulle couvre la colonne entière, et une vue tableau (« Voir les chiffres ») est proposée sous chaque graphique.

### 5.7 ter Exports Excel (`App\Core\Xlsx`)

- **Générateur maison** (`Xlsx`), sans dépendance (extension PHP `zip`) : cellules typées — montants en centimes → nombre à 2 décimales, quantités, taux, pourcentages, vraies dates. En-tête figé, filtre automatique. Le texte est toujours écrit comme texte, jamais comme formule.
- **Listes** : `?format=xlsx` sur l'index de chaque liste (RFQ, quotations, PO clients et fournisseurs, expéditions, factures, avoirs, paiements, factures et paiements fournisseurs), avec les mêmes filtres serveur que l'écran. Bouton « Exporter (Excel) » via `export_button()`. Les filtres de colonne DataTables (côté navigateur) ne sont pas repris.
- **Rapports** : relevés clients (liste et relevé d'un client), dettes et relevés fournisseurs (une feuille par devise), marge réelle, tableau de bord finance (4 feuilles).
- **Pack comptable** (`/exports`, `reports.view`) : une période (mois précédent par défaut), quatre feuilles — journal des ventes (factures émises, avoirs en négatif), journal des achats (devise et USD), encaissements, décaissements. Brouillons et pièces annulées exclus.
- Les droits sont ceux de la page : exporter n'ouvre aucun accès supplémentaire.

### 5.7 quater Suivi commercial, délais et relances

- **Délais** (`Domain\Sales\Deadlines`, `Services\DeadlinePolicy`) : décompte en jours ouvrés (lundi → vendredi, jours fériés non déduits) ou calendaires, selon `delay_mode`. Le délai de réponse RFQ et la validité des devis se règlent par client (`clients.rfq_response_days`, `quote_validity_days`), sinon ceux de la société. Une RFQ saisie sans date limite reçoit « réception + délai » à 17 h. La validité reste en jours calendaires.
- **Suivi commercial** (`/tracking`, et fiche client — `SalesTrackingService`) : une ligne par RFQ. Elle montre la réponse (premier envoi d'un devis, toutes versions), le délai en jours, le respect de l'échéance, le devis en cours (montant, statut, relances), l'étape (à répondre, en attente du client, PO attendu, gagné, perdu, annulé) et le PO client. Indicateurs : RFQ répondues, réponses à l'heure, délai moyen, conversion (gagnées ÷ tranchées), montants gagnés et perdus, motifs de perte. Exportable en Excel.
- **Devis perdu** : motif obligatoire (`QuotationService::LOST_REASONS` : prix, délai, concurrent, spécifications, projet annulé, sans réponse, autre) ; commentaire obligatoire pour « autre ».
- **Relances** (`FollowupService`, table `followups`, page `/followups`) :
  - devis envoyé sans réponse (`quote_followup_days`, depuis l'envoi) ;
  - devis accepté sans PO client (`client_po_followup_days`, depuis l'acceptation) ;
  - PO fournisseur non confirmé (`supplier_confirm_followup_days`, depuis l'envoi) ;
  - mise à disposition à vérifier `supplier_ready_check_days` jours avant la date prévue.
  - Le rythme « 5, 10 » donne l'échéance de la n-ième relance ; noter une relance (moyen, date, compte rendu) programme la suivante. L'alerte « relances à faire » remplace l'ancienne « PO fournisseur non confirmé ». Les devis expirés sans réponse sont signalés « à clôturer ».

### 5.7 quinquies Tableaux compacts et exports exécutifs

- **Moteur commun** (`app.js`, tous les `table.table`) : une ligne par enregistrement ; le détail secondaire est dans un `<template data-detail>` rendu par `row_detail()` et s'ouvre sous la ligne (chevron, « Tout déplier »). La recherche couvre aussi le détail. Menu « Colonnes » mémorisé par navigateur. Filtres déclarés sur l'en-tête (`data-filter`). `data-priority="low"` masque une colonne sur mobile ; `data-hidden` la masque par défaut.
- **Exports de la vue** (barre du tableau, `eth-export.js`, ExcelJS et pdfmake chargés au premier clic) : colonnes visibles, lignes filtrées et triées. Typage par `th[data-x]` (money, percent, qty, int, date, text, none), valeur brute `td[data-export]` sinon `data-order`. Excel : bandeau (société, titre, filtres, édition), en-tête coloré, zébrage, totaux SOUS.TOTAL, volet figé, impression paysage. PDF : A4 paysage, en-tête répété, totaux, pied numéroté.
- **« Excel complet »** (serveur, `Xlsx`) : toutes les colonnes, même mise en forme, totaux limités aux montants dans une seule devise.

### 5.7 sexies Saisie des RFQ, demandes de prix fournisseurs, envois AJAX

- **Unités de mesure** (table `units`, `/units`) : code stable copié sur les lignes de documents, libellés FR / EN, désactivation au lieu de suppression ; `unit_label()` partout. Clé étrangère `items.unit → units.code`.
- **Saisie d'une RFQ** : document du client obligatoire à la création (`rfq_files`, formats bureautique et e-mail admis), aperçu à côté de la saisie (PDF / image via URL `blob:`, CSP `frame-src 'self' blob:`). Colonne Article : recherche (code, nom, fabricant, réf.) ; un nom inconnu crée l'article à l'enregistrement (code `ART-AAAA-NNNN`, repris s'il existe déjà sous ce nom).
- **Demande de prix fournisseur** (`supplier_quotes.reference` `DP-AAAA-NNNN`, `response_due`, `message`, lignes `supplier_quote_requested_lines`) : préparée dans une fenêtre de la fiche RFQ (fournisseurs, articles et quantités), document `/sourcing/{id}/print` sans nom du client final ; la saisie de l'offre se limite aux lignes demandées.
- **Fiche RFQ** : référence client en tête, avancement (RFQ → demandes de prix → offres → devis → PO client), prochaine étape mise en avant.
- **Soumissions** (`app.js`, `initAjaxForms`) : tout formulaire POST passe par une confirmation résumant la saisie (SweetAlert), puis part en AJAX ; la page d'arrivée (redirection après POST, inchangée côté serveur) remplace le contenu, le menu et la cloche, l'adresse suit, le message flash devient une notification. Exclus : connexion, déconnexion, langue, `data-no-ajax`. En cas d'échec, la soumission classique reste possible. Les composants de page sont réinitialisés par `initPage()`.
### 5.8 Alertes (source unique : `AlertService`)

Elles alimentent la cloche, les pastilles du menu et le tableau de bord, et alimenteront l'e-mail quotidien prévu en phase 5. Chacune mène à la liste filtrée correspondante :

- RFQ en retard de réponse ;
- RFQ à répondre sous 48 h ;
- demandes de prix restées sans réponse (délai paramétrable) ;
- quotations à approuver ;
- quotations envoyées qui expirent sous 5 jours ;
- taux de change de plus de 7 jours ;
- PO clients reçus depuis plus de 2 jours et pas entièrement commandés ;
- PO fournisseurs en brouillon ;
- PO fournisseurs envoyés sans confirmation depuis 3 jours ;
- expéditions dont l'ETA est dépassée ;
- PO fournisseurs disponibles mais pas encore enlevés ;
- factures client échues, brouillons à émettre, PO clients livrés à facturer ;
- factures fournisseurs en écart, factures fournisseurs échues.

### 5.9 Finance

**Factures client** (`InvoiceService`, `Domain/Invoicing`)
- Une facture porte sur un PO client et ne contient que du livré avec POD, éventuellement réparti sur plusieurs expéditions. Une même quantité n'est jamais facturée deux fois : les brouillons comptent aussi.
- Le brouillon n'a pas de numéro et s'imprime comme proforma. À l'émission, le numéro `FAC-AAAA-NNNN`, les totaux et l'échéance (date + délai du PO) sont figés.
- Une facture émise n'est annulable que si aucun encaissement ne lui est imputé, avec un motif. Son numéro reste dans la série.
- Le statut suit les encaissements : `issued` → `partially_paid` → `paid`.

**Avoirs clients** (`CreditNoteService`, `CreditNoteRules`)
- Sur une facture émise, payée ou non. Numéro `AV-AAAA-NNNN`. Un avoir ne se modifie ni ne se supprime.
- Chaque ligne crédite une ligne de facture : une quantité au prix facturé (retour, manquant), ou toute la quantité à un prix réduit (remise, erreur de prix). La TVA est celle de la facture.
- Plafonds, cumulés sur tous les avoirs : quantité ≤ facturé, prix ≤ prix facturé, montant ≤ montant de la ligne.
- L'avoir solde d'abord le reste dû de sa facture. Le surplus (facture déjà payée) devient un crédit client, imputable ensuite sur d'autres factures ouvertes.
- Le reste dû et le statut des factures tiennent compte des avoirs imputés. Une facture qui a un avoir ne peut plus être annulée.
- Le relevé client porte les avoirs au crédit. La marge réelle déduit les avoirs du chiffre d'affaires ; le coût des marchandises retournées n'est pas déduit (prudence : la marchandise n'est pas forcément revendable).

**Encaissements** (`PaymentService`, `Receivables`)
- Numéro `ENC-AAAA-NNNN`. L'imputation est saisie facture par facture ; le bouton « Répartir automatiquement » commence par les plus anciennes, avec la même règle en PHP et en JS.
- Un encaissement ne peut pas imputer plus que son montant, ni plus que le solde d'une facture. Le non-imputé reste une avance client, imputable plus tard.

**Relevés et balance âgée** (`StatementController`)
- Relevé par période : solde d'ouverture, factures (débit), annulations et encaissements (crédit), solde progressif. Il s'imprime.
- Balance âgée par client : non échu, 1–30, 31–60, 61–90, +90 jours.

**Factures fournisseurs** (`SupplierInvoiceService`, `Domain/Purchasing`)
- Une facture fournisseur porte sur un PO fournisseur envoyé ou confirmé. Elle est saisie telle que reçue, dans la devise du PO, avec le taux du jour de la facture pour la contre-valeur USD.
- Numéro interne `FF-AAAA-NNNN`. Le numéro du fournisseur est unique par fournisseur (contrôle de doublon).
- **Rapprochement à 3 voies** (`ThreeWayMatch`), ligne par ligne, sur le cumul des factures non annulées :
  - facturé > commandé ;
  - facturé > reçu (POD) ;
  - prix > prix du PO + tolérance (paramètre, 0 % par défaut). Un prix inférieur n'est pas un écart.
- Sans écart, la facture est approuvée d'office (« bon à payer »). Avec un écart, elle passe « en attente » et est bloquée au paiement. Deux sorties possibles :
  - « Re-vérifier » après une nouvelle POD ;
  - validation par un responsable (`supplier_invoices.approve` : admin, manager), motif obligatoire et tracé.
- La facture scannée est facultative et s'ouvre dans l'aperçu.

**Paiements fournisseurs** (`SupplierPaymentService`)
- Numéro `PF-AAAA-NNNN`. On paie dans la devise de la facture, au taux appliqué par la banque ce jour-là.
- Seules les factures « bon à payer » reçoivent une imputation, jamais au-delà de leur reste dû (même règle que les encaissements).
- Un acompte versé avant facture reste en avance fournisseur, imputable ensuite sur les factures de la même devise.
- Sur chaque imputation, la fiche montre le résultat de change : USD au taux de la facture comparé à l'USD au taux payé.
- Dettes fournisseurs : solde par fournisseur et par devise, prochaine échéance, échu ; relevé par devise.

**Marge réelle** (`MarginService`, `Domain/Finance`)
- Calculée par ligne de PO client, sur ce qui a été facturé au client (HT, USD).
- Coût réel :
  - factures fournisseurs de la ligne, hors TVA, au taux de chaque paiement pour la part payée, au taux de la facture pour le reste ;
  - au prorata si le fournisseur a facturé plus que ce qui a été facturé au client ;
  - partie pas encore facturée par le fournisseur : au coût du PO (à défaut, au coût estimé de la quotation), la marge est alors « provisoire ».
- Écart de change : coût réel comparé au même coût au taux figé sur le PO.
- Affichée sur la fiche du PO client (permission `reports.view`) et dans Rapports, filtrable par période, avec la marge prévue en regard.

**Frais logistiques** (`SupplierInvoiceService::recordLogistics`, `LandedCost`)
- Facture fournisseur de type `logistics` (transitaire, agence en douane, frontière…), sans PO ni rapprochement : bon à payer d'office.
- Chaque ligne porte sur une expédition et une nature : transport, droits et taxes de douane, agence en douane, frais de frontière, assurance, autre. Une facture mensuelle peut donc couvrir plusieurs expéditions.
- Mêmes paiements, acomptes, dettes et relevés que les achats ; carte « Frais logistiques » sur la fiche expédition.
- Marge réelle : le coût USD réel de chaque frais est réparti sur les lignes de l'expédition au prorata de leur valeur d'achat (plus fort reste, aucun centime perdu), puis compté sur la part facturée au client.

---

## 6. Sécurité

| Menace | Mesures |
|---|---|
| Bourrage d'identifiants | Mots de passe en Argon2id ; 5 échecs par e-mail et 20 par IP sur 15 min ; même message d'erreur et même temps de réponse que le compte existe ou non. |
| Vol ou fixation de session | Cookie HttpOnly, SameSite=Lax et Secure ; nouvel identifiant de session à la connexion ; expiration après 30 min d'inactivité et 12 h au total ; révocation de toutes les sessions possible (`session_version`). |
| CSRF | Jeton sur tout POST, y compris la connexion et le changement de langue, et contrôle de l'en-tête `Origin`. |
| XSS | Échappement systématique (`e()`) ; CSP `script-src 'self'` sans script inline (vérifié à chaque test de pages) ; données vers le JS uniquement en blocs JSON. |
| Injection SQL | Requêtes préparées natives, multi-requêtes désactivées, colonnes écrites limitées à une liste blanche (`MasterRepository::COLUMNS`), `LIKE` échappé. |
| Élévation de droits | Permission vérifiée par le routeur sur chaque route ; règles sensibles (approbation) revérifiées dans le service. |
| Fuite d'information commerciale | Le document client exclut coûts, fournisseurs et marges ; la quotation est verrouillée après envoi ; les scans de factures fournisseurs exigent `supplier_invoices.view` (`FileController::RESTRICTED`). |
| Erreurs | `APP_DEBUG` interdit en production (le démarrage échoue sinon) ; mots de passe exclus des traces (`#[SensitiveParameter]`). |
| Postes partagés | `Cache-Control: no-store` sur toutes les pages dynamiques. |
| Serveur | PHP-FPM, jamais PHP-CGI exposé (CVE-2024-4577) ; vhost sur `public/` ; utilisateur SQL dédié, sans droit de DDL. |

À venir :
- double authentification TOTP pour `admin`, `manager` et `finance` ;
- changement de mot de passe imposé à la première connexion ;
- longueur minimale de 12 caractères, imposée dans l'interface.

---

## 7. Feuille de route

| Phase | Contenu | État |
|---|---|---|
| **1 — Socle commercial** | Référentiel (clients, fournisseurs, articles), devises et taux, RFQ, sourcing et comparaison, quotations (versions, approbation, impression), tableau de bord, alertes | **Livrée** |
| **2 — Commandes** | PO client depuis la quotation (écarts signalés), PO fournisseurs préparés automatiquement par fournisseur retenu ou saisis à la main, couverture ligne par ligne, taux figé à l'envoi, confirmation, bon de commande imprimable, alertes | **Livrée** |
| **3 — Logistique** | Expéditions multi-PO (envois partiels), transitaire ou fournisseur, jalons jusqu'à Kasumbalesa et le dédouanement, POD avec document signé et quantités reçues, journal scellé et vérification nocturne, statut « livré » du PO client, alertes | **Livrée** |
| **4 — Finance** | Factures client depuis les POD (livraisons partielles), encaissements et imputation, relevés client et balance âgée, factures fournisseur rapprochées du PO et de la réception | **Livrée** |
| **4 — Finance (suite)** | Paiements fournisseurs (devise, taux du jour, acomptes), dettes et relevés fournisseurs, marge réelle par PO client avec écart de change | **Livrée** |
| **4 — Finance (frais)** | Frais de transport et de dédouanement par expédition, imputés à la marge réelle | **Livrée** |
| **4 — Finance (avoirs)** | Avoirs clients : retours, remises, erreurs de prix, crédit client imputable | **Livrée** |
| **5 — Pilotage (finance)** | Tableau de bord finance : trésorerie 8 semaines, chiffre d'affaires et marge par mois, meilleurs clients et fournisseurs, DSO / DPO, taux de devis gagnés | **Livrée** |
| **5 — Pilotage (exports)** | Exports Excel des listes et rapports, pack comptable mensuel | **Livrée** |
| **5 — Suivi commercial** | Suivi RFQ → devis → PO par client, délais paramétrables (ouvrés / calendaires), relances clients et fournisseurs, motifs de perte | **Livrée** |
| **5 — Pilotage** | E-mail quotidien des alertes, envoi des documents par e-mail, gestion des utilisateurs, journal d'activité consultable | À faire |
