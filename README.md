# SJ4WEB - Marge Commande

**Version :** 1.0.0  
**Auteur :** SJ4WEB.FR  
**Compatibilité :** PrestaShop >= 8.1

## 📌 Description

Ce module affiche la **marge nette** d'une commande dans le back-office PrestaShop, directement dans la fiche commande (colonne latérale).  
Il permet de prendre en compte :
- le **prix d’achat fournisseur** des produits,
- les **frais de dropshipping** configurables par fabricant,
- les **frais de paiement** enregistrés manuellement.

## ⚙️ Fonctionnalités

- Affichage de la **marge nette** dans l'onglet commande (via `displayAdminOrderSide`).
- Saisie des **frais de dropshipping** par fabricant, en JSON :
    - Type `percent` → % sur le total HT des produits du fabricant.
    - Type `fixed` → montant fixe par commande.
    - Type `per_quantity` → montant basé sur le nombre de produits.
- Téléchargement d’un **exemple de JSON** directement depuis la configuration.
- Enregistrement des **frais de paiement** dans la table `order_fees`.

## 🛠 Installation

1. Copier le module dans le répertoire `/modules/sj4webmargecommande/`.
2. Installer depuis le back-office PrestaShop.
3. Saisir la configuration des frais dans le BO du module (format JSON).

## 🧾 Exemple de configuration JSON

```json
{
  "1": { "type": "percent", "value": 10 },
  "2": { "type": "fixed", "value": 10 },
  "3": {
    "type": "per_quantity",
    "steps": [
      { "quantity": 1, "value": 2.40 },
      { "quantity": 5, "value": 3.50 },
      { "quantity": 10, "value": 5.70 },
      { "quantity": 1000, "value": 6.80 }
    ]
  }
}
```

## 🧩 Hooks utilisés

- `displayAdminOrderSide` : affichage de la marge.
- `actionAdminControllerSetMedia` : gestion du lien de téléchargement JSON.

## 💾 Base de données

Création de la table `PREFIX_order_fees` :

```sql
CREATE TABLE PREFIX_order_fees (
  id_order_fee INT AUTO_INCREMENT PRIMARY KEY,
  id_order INT NOT NULL,
  method VARCHAR(50),
  fee DECIMAL(10,2) NOT NULL,
  date_add TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 🚧 À noter

- Aucune surcharge du core PrestaShop.
- Les **frais de paiement** doivent être ajoutés manuellement via l'interface de configuration.

---

© SJ4WEB.FR – Tous droits réservés.
