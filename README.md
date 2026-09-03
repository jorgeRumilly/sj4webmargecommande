# sj4webmargecommande — SJ4WEB Marge commande

Marge **théorique** d'une commande, pour un catalogue majoritairement en dropshipping.

## Formule (tout HT, P&L complet — port inclus)

```
revenu           = total_paid_tax_excl + avoirs/cartes cadeaux − ventes de carte cadeau
                   − remb_produits − remb_port                          (produits + port facturé, net des vraies remises)
coût d'achat     = Σ purchase_supplier_price × (qté − qté_remboursée)
coût fournisseurs = Σ_fournisseur ( frais de drop  ±  port réel payé )   (voir modèle drop/port)
commission       = Σ order_fees.fee  (commission de paiement, HT)
marge nette      = revenu − coût d'achat − coût fournisseurs − commission
```

- **Vraies remises déjà prises en compte** : `total_paid_tax_excl` intègre soldes,
  « -5% / -3% CB ou Virement », coupons promo. Widget et liste = **même** `MarginCalculator`.
- **Port inclus** : le revenu inclut le port facturé au client ; le coût inclut le port
  réel payé. Port offert → pas de revenu port mais coût soustrait (la perte apparaît).
  Port facturé → Δ port = marge du transport, intégrée. Le widget affiche
  `Port facturé / Port payé / Δ`.
- **Remboursements** : produits + port retirés du revenu, quantités remboursées retirées
  du coût. Drop et commission **non** proratisés (conservateur).

## Cartes cadeaux & avoirs = moyen de paiement, pas remise

Une carte cadeau ou un avoir a déjà été encaissé à son émission. Sur la commande où il
est **dépensé**, PrestaShop le soustrait de `total_paid_tax_excl` comme un bon de
réduction : le module **rajoute son montant HT au revenu** (`StoreCreditResolver`).

| Cas | Détection | Effet |
|---|---|---|
| Carte cadeau dépensée | `order_cart_rule.name = 'La carte cadeau'` | `+ montant HT` au revenu |
| Avoir dépensé | `cart_rule.code LIKE 'REFUND-%'`, `name LIKE 'Remboursement commande%'` ou `name LIKE "Bon d'achat%"` | `+ montant HT` au revenu |
| **Vente** de carte cadeau | ligne produit ∈ `SJ4WEB_MARGIN_GIFTCARD_PRODUCTS` (config) | ligne exclue du revenu **et** du coût → marge ≈ `− commission` |
| Remb. frais de retour (`REMFDR_*`) | — | **laissé en remise** (c'est notre coût) |

Garde-fous (commandes retravaillées à la main) : dédoublonnage des lignes
`order_cart_rule` de même montant, et le rajout est **plafonné** à ce qui a réellement
été remisé (`Σ lignes + port − total_paid_tax_excl`) — le revenu ne peut jamais
dépasser la valeur brute marchandise + port de la commande.

Champs cache : `store_credit_ht`, `gift_card_sales_ht`, `has_store_credit`
(colonne + filtre « Avoir/CC » dans la liste BO).

## Modèle drop / port

Le **frais de drop** (règle fournisseur) ≠ **frais de port**. Sauf pour Castex où ils
sont confondus. Chaque règle a un interrupteur **« le frais de drop couvre déjà le port »** :

| Interrupteur | Coût effectif du fournisseur dans la commande |
|---|---|
| **ON** (Castex) | `port réel saisi` si renseigné, sinon l'estimation de la règle (tout compris) |
| **OFF** (Tradilinge, Essix…) | `frais de drop (règle)` **+** `port réel saisi` (0 tant que non saisi → badge « port estimé », marge sur-évaluée) |

Le **port réel payé** se saisit sur la fiche commande (bloc *« Frais de port réels
payés, par fournisseur »* dans le widget marge), une ligne par fournisseur présent,
enregistrement AJAX + recalcul immédiat de la marge.

## Coût dropshipping — règles par fournisseur (`id_supplier`)

Écran BO **Commandes → Règles de coût dropshipping**. Une règle par fournisseur,
d'un des types :

| Type | Calcul |
|---|---|
| `percent` | % d'une base — **prix d'achat** (défaut) ou **prix de vente HT hors coupons** (`percent_base`) — des produits **expédiés** de ce fournisseur |
| `fixed` | montant fixe dès qu'au moins un produit du fournisseur est expédié |
| `per_quantity` | premier palier dont « qté max » ≥ quantité **expédiée** du fournisseur |
| `bucket_flat` | somme des forfaits des **groupes de type** présents (produits expédiés) |

**Expédié** = quantité commandée − quantité remboursée. Un produit retourné (rupture,
avoir) ne génère pas de frais de dropship. Le port n'entre jamais dans la base du `%`.

**`bucket_flat`** : un groupe = un libellé + un montant + des catégories. Un produit
appartient à un groupe si une de ses catégories **est** une catégorie du groupe **ou
une sous-catégorie** (arbre nested-set). Un groupe est facturé une fois s'il a ≥ 1
produit correspondant (quantité ignorée). Priorité = ordre des groupes.

Exemple **Manufacture Castex** :
- groupe « Oreiller » → catégorie *Oreiller* → 8,80 €
- groupe « Couchage » → catégories *Couette* + *Surmatelas* → 9,60 €
- 2 oreillers + 1 couette ⇒ 8,80 + 9,60 = 18,40 €.

Le coût drop **s'additionne entre fournisseurs** sur les commandes multi-fournisseurs.

## Performance

La liste BO **Marges commandes** lit une table cache précalculée
`sj4web_order_margin` (filtre / tri / pagination **en SQL**). Le cache est maintenu par :
- hooks `actionValidateOrder`, `actionOrderEdited`, `actionObjectOrderSlipAddAfter`,
  `actionOrderStatusPostUpdate` ;
- hook `actionSj4webOrderFeeCaptured` émis par `sj4web_payplugreport` quand une
  commission tombe en différé ;
- **cron** (fenêtre glissante) + boutons BO *Calculer les manquantes* / *Tout reconstruire* ;
- rafraîchissement opportuniste à l'ouverture de la fiche commande.

### Cron

```bash
curl -s "https://VOTRE-BOUTIQUE/module/sj4webmargecommande/cron?token=LE_JETON"
```
`&scope=missing` pour ne traiter que les commandes absentes du cache.

## Configuration

BO → Modules → *SJ4WEB - Marge commande* : fenêtre du cron, seuils couleur de marge,
**IDs produit « carte cadeau »** (`SJ4WEB_MARGIN_GIFTCARD_PRODUCTS`, défaut =
`3391061` actif + 7 anciens), URL du cron + régénération de jeton, liens vers les
2 écrans, boutons de recalcul.

## Tables

`sj4web_margin_drop_rule` / `_step` / `_bucket` / `_bucket_category`,
`sj4web_order_margin`. **`order_fees` n'est plus supprimée à la désinstallation**
(infrastructure partagée avec `sj4web_payplugreport`).

## Migration 1.1.0 → 2.0.0

`upgrade/upgrade-2_0_0.php` : crée le schéma, enregistre les hooks + le nouvel onglet,
et **migre l'ancien JSON `SJ4WEB_FEE_LIST`** (clé `id_manufacturer`) vers des règles
par `id_supplier` (correspondance par nom, 1:1 sur ce catalogue). L'ancienne valeur
est conservée en sauvegarde. Après la mise à jour : ouvrir *Règles de coût
dropshipping*, vérifier / compléter (dont Castex), puis *Tout reconstruire*.

## Migration 2.0.1 → 2.0.2

`upgrade/upgrade-2.0.2.php` : ajoute les colonnes `store_credit_ht` /
`gift_card_sales_ht` / `has_store_credit` à `sj4web_order_margin` et la config
`SJ4WEB_MARGIN_GIFTCARD_PRODUCTS`. Traitement des **cartes cadeaux & avoirs comme
moyen de paiement** (cf. section ci-dessus). ⚠ La formule de revenu change →
***Tout reconstruire*** le cache après la mise à jour.
