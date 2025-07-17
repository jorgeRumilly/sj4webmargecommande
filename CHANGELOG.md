# CHANGELOG

## [1.1.0] - 2025-07-17
### Ajout
- Nouveau contrôleur BO `AdminSj4webMargeCommandeFeesController`
- Ajout d’un onglet Back-Office "Marge Commande - Liste" sous le menu Commandes
- Affichage d’un tableau complet des commandes avec :
  - ID commande, référence, date
  - Totaux HT / TTC, livraison HT / TTC
  - Montants remboursés produits & livraison
  - Nombre de produits
  - Moyen de paiement
  - Commission TTC
  - Coût dropshipping
  - Marge nette calculée
### Amélioration
- Aucune modification du comportement existant
- Structure modulaire respectée pour compatibilité maximale

## [1.0.0] - 2024-06-10
### Ajout
- Première version stable du module `sj4webmargecommande`
- Affichage de la marge nette dans la fiche commande (Back-Office)
- Calcul automatique :
    - du coût d’achat total de la commande
    - des frais de dropshipping selon le fabricant et la grille configurée (JSON)
    - des commissions enregistrées dans la table `order_fees`
- Interface de configuration JSON pour définir les frais par fabricant
- Bouton de téléchargement d’un exemple JSON

### Technique
- Utilisation du hook `displayAdminOrderSide` pour l’affichage sur la fiche commande
- Fonctionne avec PrestaShop >= 8.1.0
- Utilisation du nouveau système de traduction PrestaShop 8+
