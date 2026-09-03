# CHANGELOG — sj4webmargecommande

## [2.0.0] - 2026-09-01 (suite : base de calcul du coût de drop)

- **Coût de drop calculé sur les quantités NON remboursées** uniquement (produits
  retournés = pas de frais de dropship). S'applique à tous les types : `percent`,
  `fixed` (0 si tout remboursé), `per_quantity`, `bucket_flat`.
- **`percent` : base de calcul** paramétrable par règle (`percent_base`) :
  - `purchase` (défaut) : % du **prix d'achat** des produits expédiés,
  - `sale` : % de **notre prix de vente HT**, `unit_price_tax_excl` (donc **avant
    coupons** / bons de réduction ; les remises catalogue restent incluses).
  Le port n'entre dans aucune des deux bases.
- Écran CRUD : sélecteur *Base du pourcentage* (visible pour le type `percent`).
- Correctif du bug : l'ancien calcul prenait 10 % de la somme de **toutes** les lignes
  (remboursées incluses) sur le **prix de vente** → nettement sur-évalué.

⚠️ **Re-exécuter *Tout reconstruire*** après cette mise à jour.

## [2.0.0] - 2026-08-29 (suite : modèle drop/port + P&L complet)

### Frais de port réels
- Distinction **frais de drop ≠ frais de port**. Interrupteur `drop_covers_shipping`
  par règle fournisseur (ON = tout compris, ex. Castex ; OFF = port en sus).
- Nouvelle table `sj4web_order_drop_actual` : port réel payé, par commande, par fournisseur.
- Bloc de saisie AJAX dans le widget fiche commande (une ligne / fournisseur présent) +
  recalcul immédiat. Endpoint `AdminSj4webMarginDropRules::ajaxProcessSaveOrderDrop`.
- `DropCostResolver::resolve()` : coût effectif = `drop ± port` selon l'interrupteur ;
  renvoie le détail par fournisseur + `port_pending` + `port_paid`.

### Marge — P&L complet (port inclus)
- `revenu = total_paid_tax_excl − remb_produits − remb_port` (le port facturé au client
  entre désormais dans le revenu).
- `coût fournisseurs = Σ (drop estimé/réel + port réel)`.
- Nouveaux champs : `shipping_charged_ht`, `port_paid_ht`, `port_pending`, `shipping_delta_ht`.
- Widget : détail par fournisseur (drop est./réel + port), ligne `Port facturé / payé / Δ`,
  badges couleur, badge « port estimé » tant qu'un fournisseur non‑all‑in n'a pas de port réel.
- Liste BO : colonnes `Port facturé`, `Port payé`, ⚠ sur `Coût fourn.` si port manquant.
- `sql/install.php` idempotent + auto‑migrant (ADD COLUMN conditionnel, MySQL 8).

⚠️ **Après cette mise à jour : re‑exécuter *Tout reconstruire*** — les lignes de cache
existantes ont été calculées avec l'ancienne formule (port en pass‑through).

## [2.0.0] - 2026-08-29

Refonte : coût dropshipping par fournisseur, unification de la marge, mise à l'échelle
de la liste BO.

### Coût dropshipping
- Modèle par **`id_supplier`** (au lieu de `id_manufacturer`), en base + écran CRUD
  (**Règles de coût dropshipping**) au lieu d'un JSON de configuration.
- Nouveau type **`bucket_flat`** : forfait par groupe de type de produit présent dans
  la commande, appartenance par **arbre de catégories** (nested-set). Répond au cas
  Castex (oreiller 8,80 € / couchage 9,60 €, cumulés par groupe et par fournisseur).
- Types `percent` / `fixed` / `per_quantity` conservés.

### Marge
- **Formule unique** (`MarginCalculator`) pour le widget fiche commande et la liste —
  fin de la divergence entre les deux.
- Revenu = `total_paid_tax_excl − total_shipping_tax_excl − remboursements_produits`
  → **prend en compte les bons de réduction** (soldes, -5% CB/virement, cartes
  cadeaux) qui sont déjà dans `total_paid_tax_excl`.
- **Remboursements déduits** (revenu et quantités de coût).
- `commission` = `SUM(order_fees.fee)` (au lieu de la 1re ligne seulement) ; libellé
  corrigé en **HT**.
- Indicateur *coût d'achat incomplet* quand une ligne n'a pas de prix d'achat fournisseur.

### Performance
- La liste BO lit la table cache **`sj4web_order_margin`** → filtre / tri / pagination
  **en SQL**. Fin du chargement de toutes les commandes + `new Order()` par ligne au
  tri sur un champ calculé.
- Cache maintenu par hooks (`actionValidateOrder`, `actionOrderEdited`,
  `actionObjectOrderSlipAddAfter`, `actionOrderStatusPostUpdate`), par le hook
  `actionSj4webOrderFeeCaptured` (émis par `sj4web_payplugreport`), par un **cron**
  (fenêtre glissante) et par des **boutons de recalcul** (manquantes / tout).

### Widget fiche commande
- **Moyen de paiement** affiché.
- Détail : revenu, coût d'achat (+ badge « incomplet »), coût drop (par fournisseur),
  commission (+ %), remboursements déduits, marge nette + taux de marque / de marge.

### Divers
- `uninstall` ne **DROP plus `order_fees`** (partagée avec `sj4web_payplugreport`).
- 2e onglet BO **Règles de coût dropshipping**.
- Sécurité : jeton admin vérifié (`hash_equals`) sur les actions ; cron protégé par
  jeton (`set_time_limit(0)`, `ignore_user_abort`) ; SELECT en `executeS()` ;
  `catch (Throwable)` sur les points d'entrée.
- Traductions XLF FR/EN pour le module et les templates (domaine
  `Modules.Sj4webmargecommande.Admin`).
- `upgrade/upgrade-2_0_0.php` : schéma, hooks, onglet, migration de l'ancien JSON
  `SJ4WEB_FEE_LIST` (fabricant → fournisseur par nom).

### À faire (non bloquant)
- Migrer les libellés des contrôleurs admin (`$this->l()`) vers `trans()` + XLF.
- Signaler visuellement les commandes avec carte cadeau (marge faussée).

## [1.1.0] - 2025-07-17
- Contrôleur BO `AdminSj4webMargeCommandeFeesController` + onglet liste des marges.

## [1.0.0] - 2024-06-10
- Version initiale : marge nette en fiche commande, coût d'achat, frais drop par
  fabricant (JSON), commissions `order_fees`.
