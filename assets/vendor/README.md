# Bibliothèques tierces livrées avec le plugin

Ces fichiers sont servis par le site, et non depuis un CDN. Trois raisons :

- **Vie privée.** Appeler unpkg.com envoyait l'adresse IP de chaque visiteur à un
  hébergeur américain, sans consentement possible. Pour un site public, c'est
  généralement rédhibitoire.
- **Disponibilité.** Une panne du CDN faisait disparaître la carte.
- **Intégrité.** Du code tiers s'exécutait sur le site sans aucun contrôle.

## Contenu

| Dossier | Bibliothèque | Version | Licence | Source |
|---|---|---|---|---|
| `leaflet/` | Leaflet | 1.9.4 | BSD-2-Clause | `https://unpkg.com/leaflet@1.9.4/dist/` |
| `markercluster/` | Leaflet.markercluster | 1.5.3 | MIT | `https://unpkg.com/leaflet.markercluster@1.5.3/dist/` |

`leaflet/images/` contient les icônes de marqueur référencées par `leaflet.css`
et par le code de Leaflet. Le dossier doit rester à côté de `leaflet.css` :
c'est ainsi que Leaflet retrouve ses images.

## Mise à jour

Les versions sont déclarées dans `Pivot_Templates::LEAFLET_VERSION` et
`Pivot_Templates::MARKERCLUSTER_VERSION`, qui servent aussi de numéro de version
aux fichiers enregistrés auprès de WordPress. Pour monter de version :

1. remplacer les fichiers depuis la source indiquée ci-dessus, images comprises ;
2. mettre à jour les deux constantes ;
3. vérifier une page de listing avec carte, en mode groupé et non groupé.

Rien d'autre dans le plugin ne dépend de ces versions.
