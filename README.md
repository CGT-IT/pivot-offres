# PIVOT Offres

Extension WordPress qui publie les offres touristiques de **PIVOT/Web 3.1** (Commissariat général au Tourisme, Wallonie) : pages de listing paramétrables, recherche et pagination entièrement côté navigateur, cartographie, fiches détail optimisées pour le référencement.

**Aucune offre n'est écrite en base de données.** Tout passe par un cache fichier, renouvelé automatiquement et réinitialisable à la main.

**Multilingue fr / nl / en / de.** Les traductions des contenus viennent de PIVOT ; l'interface d'administration et les textes visibles sont livrés traduits. Les langues publiées sont celles de l'extension de traduction du site — WPML, Polylang, TranslatePress et Weglot sont reconnus d'office ; sans extension, le site reste monolingue.

---

## Installation

1. Copiez le dossier `pivot-offres` dans `wp-content/plugins/`.
2. Activez l'extension.
3. Ouvrez **PIVOT → Réglages**, choisissez l'environnement (stage ou production), collez la clé `ws_key` correspondante, puis cliquez sur **Tester la connexion**.
4. Créez votre première page dans **PIVOT → Ajouter une page**.

L'activation crée la table de journal et les deux dossiers de cache — `wp-content/uploads/pivot-cache/` pour les index servis au navigateur, `wp-content/pivot-cache-private/` pour tout le reste —, planifie les tâches de reconstruction et rafraîchit les permaliens.

**Prérequis** : WordPress 6.0, PHP 7.4, permaliens autres que « simple », et les dossiers `wp-content` et `uploads` accessibles en écriture.

---

## Comment ça marche

### Le cycle des données

```
PIVOT/Web ──► index JSON (fichier)  ──► navigateur : recherche, filtres, pagination, carte
   │            reconstruit par cron, tenu à jour chaque nuit par différentiel
   │                  │ si « complet avec offres liées »
   │                  ▼
   └────────► fiche détail (fichier) ──► page /details/CODE&type=TYPE
```

Une page de listing exécute sa requête pré-programmée en entier **une seule fois par cycle de cache**, en mode paginé, et en tire un index compact : pour chaque offre, uniquement ce que la page affiche et filtre. Cet index est servi au navigateur comme fichier statique. Entre deux reconstructions, il est tenu à jour chaque nuit par le différentiel de PIVOT : seules les offres ajoutées, modifiées ou retirées sont redemandées (voir [Mise à jour par différentiel](#mise-à-jour-par-différentiel)).

Le visiteur qui tape dans le champ de recherche, coche un filtre ou change de page ne déclenche donc **aucun appel à PIVOT** : tout se joue dans son navigateur, sur les données déjà chargées.

### Ce qui est rendu côté serveur

La première page de résultats est écrite en HTML par PHP, à partir du même index. Les moteurs de recherche et les visiteurs sans JavaScript voient donc des offres, des liens et une pagination fonctionnelle. Le script prend ensuite la main sans recharger la page.

### Renouvellement du cache

| Contenu | Réglage | Renouvellement |
|---|---|---|
| Index des listes | `Listes d'offres` | chaque nuit par différentiel ; reconstruction complète de sécurité la nuit, une fois la durée écoulée ; en journée, seulement si l'index manque ou a été invalidé. Sans différentiel : tâche planifiée toutes les 15 minutes, un index par passage |
| Fiches détail | `Fiches détail` | à la première visite après expiration ; ou à chaque reconstruction d'un index en mode **Complet avec offres liées**, puis chaque nuit pour les offres modifiées |
| Thesaurus | `Thesaurus` | à la demande, durée longue conseillée |
| Erreurs | `Erreurs` | évite de marteler le service sur une offre absente |

Une fiche détail absente du cache coûte au visiteur un appel à PIVOT, d'une demi-seconde environ. Une page de listing réglée sur **Complet avec offres liées** reçoit, en construisant son index, chaque offre au niveau de détail de la fiche : elle la range au passage dans le cache des fiches, sans appel supplémentaire. Les fiches de ses offres s'ouvrent alors dès la première visite, que l'on vienne d'une vignette, d'un moteur de recherche ou d'un lien direct. En contrepartie, la construction de l'index est environ trois fois plus longue, et le cache privé grossit de 30 à 60 Ko par offre. Avec la mise à jour par différentiel, ces fiches sont gardées jusqu'à la reconstruction complète suivante, et celles des offres modifiées sont réécrites chaque nuit. Sans différentiel, gardez la durée `Fiches détail` au moins égale à celle des `Listes d'offres` : sinon les fiches expirent avant que la reconstruction suivante ne les renouvelle.

Reconstruction manuelle : **PIVOT → Cache et outils**, ou le bouton **Reconstruire maintenant** sur la page de listing (barre de progression, traitement par lots).

Les index volumineux sont construits par tranches : si le budget de temps est dépassé, la construction reprend en arrière-plan. Réduisez **Offres par appel** si votre hébergeur coupe les requêtes longues.

### Mise à jour par différentiel

PIVOT sait dire ce qui a changé dans une requête depuis la dernière réception validée (`query/CODE/diff`, puis `/ack`). Sur un site de 1 500 offres, c'est quelques offres par jour. Chaque nuit, à l'**Heure de la mise à jour** (04:00 par défaut, heure du site), le plugin demande donc pour chaque page de listing :

1. **Ce qui a changé**, en version légère : codes et opérations. Quand rien n'a bougé, la réponse fait 126 octets, et c'est tout pour la nuit.
2. **S'il y a des changements**, les offres concernées en un second appel, au niveau de détail de la page. L'index est réécrit localement, les fiches détail suivent, puis la réception est validée chez PIVOT.

Pour 5 pages, une nuit sans changement représente 5 appels, environ 630 octets et 6 secondes, là où une reconstruction complète télécharge 18 à 70 Mo.

**Ce qui garantit que rien ne se perd** :

- La référence du différentiel est posée au **début** de chaque reconstruction complète, avant le téléchargement : une offre modifiée pendant la construction ressortira la nuit suivante.
- La réception n'est validée qu'**après** l'écriture de l'index. Si la validation se perd, les mêmes changements reviennent la nuit suivante et sont réappliqués sans dommage.
- Tout écart ramène à une reconstruction complète, qui repose la référence : une offre modifiée ou retirée que le plugin ne connaît pas, plus de 50 changements (ou 20 % de la page), une référence perdue chez PIVOT (toutes les offres reviennent « ajoutées »), une configuration de page modifiée, des fiches locales disparues.
- Après trois échecs d'affilée — un quart d'heure d'écart, six tentatives par nuit au plus — le différentiel est réinitialisé chez PIVOT (`/clear`) et la page est reconstruite.
- La reconstruction complète reste programmée à intervalle régulier (`Listes d'offres`), la nuit : elle rattrape ce que le différentiel ne voit pas, comme la photo d'une offre changée sans que l'offre elle-même soit modifiée. **7 jours** suffisent.

**Limites** :

- PIVOT tient **un seul différentiel par clé et par requête**. Deux pages de listing sur la même requête se voleraient les changements : elles restent en reconstruction complète, et l'écran d'édition le signale. De même, décochez **Mise à jour par différentiel** sur une copie du site (préproduction, poste local) qui utilise la même clé et la même requête.
- WordPress ne lance ses tâches planifiées qu'à la première visite qui suit l'heure prévue. Pour une heure exacte, désactivez le déclenchement par les visites (`define( 'DISABLE_WP_CRON', true );` dans `wp-config.php`) et faites appeler `wp-cron.php` par une tâche cron du serveur, toutes les 5 ou 15 minutes.

L'écran d'édition d'une page de listing indique la dernière vérification et le nombre de changements appliqués. Le **Journal** consigne les échecs et les reprises ; au niveau *tout*, il garde aussi une ligne par mise à jour appliquée.

---

## Pages de listing

Chaque page associe une URL de votre site à un code de requête PIVOT. Aucune page WordPress n'est à publier : l'adresse est créée par l'extension.

| Réglage | Effet |
|---|---|
| URL | un ou plusieurs segments, ex. `sejourner/hotels` |
| Code de requête | `QRY-00-0000-0000` |
| Paramètres | pour une requête paramétrable : `radius=10`, une par ligne |
| Richesse des données | **Résumé** (rapide), **Complet** — indispensable dès qu'un filtre porte sur un champ PIVOT —, ou **Complet avec offres liées**, qui met aussi en cache les fiches détail des offres de la page (voir [Renouvellement du cache](#renouvellement-du-cache)) |
| Offres par page | pagination navigateur |
| Carte | pointe les offres géolocalisées de la page |
| Critères de recherche | voir ci-dessous |

### Ajouter un filtre : les critères suggérés

Le plus simple est de ne rien saisir. Sous le titre **Critères de recherche**, le plugin lit un échantillon de soixante offres de votre requête et propose les critères qui ont un sens, chacun avec son nombre de valeurs, sa couverture et deux ou trois exemples :

> **Province** — 5 valeurs · 100 % des offres — *Namur · Liège · Luxembourg* &nbsp; **[Ajouter]**

Un clic pose le critère entièrement réglé : libellé, source, urn, contrôle et clé d'URL. Si le critère porte sur un champ PIVOT et que la page est encore en mode résumé, la richesse des données bascule sur **Complet** au passage — sinon le champ ne serait pas renvoyé et le filtre resterait vide.

Sont écartés d'office : les champs présents sur moins de 10 % des offres, ceux qui n'ont qu'une seule valeur, ceux qui en ont presque autant que d'offres (une référence interne n'est pas un critère), les descriptifs et les coordonnées de contact.

Un champ numérique échappe à la règle des valeurs trop nombreuses : cent prix différents ne font pas une liste, mais une très bonne jauge. Il est proposé comme **nombre à comparer**, avec son étendue :

> **Nombre de personnes** — de 12 à 80 · 100 % des offres &nbsp; **[Ajouter]**

L'analyse est mise en cache pour la durée des listes d'offres ; le lien **Réanalyser les offres** la refait immédiatement.

### Régler un critère à la main

**Ajouter un critère sur mesure** ouvre un critère vierge :

- **Libellé** : ce que verra le visiteur.
- **Source** : type d'offre, localité, commune, code postal, province, ou **champ PIVOT**.
- **Contrôle** : liste déroulante, cases à cocher, saisie libre, interrupteur, nombre à comparer.

Les valeurs proposées au visiteur sont toujours déduites des offres de la page, avec leur nombre d'occurrences : elles suivent vos données sans que vous ayez à les tenir à jour.

### Critères numériques

Capacité, nombre de chambres, prix, distance, dénivelé : un nombre ne se choisit pas dans une liste, il se compare. Le contrôle **Nombre à comparer** ouvre trois réglages :

| Réglage | Valeurs |
|---|---|
| Comparaison | **Au moins (≥)**, **Au plus (≤)**, **Entre deux valeurs**, **Égal à (=)** |
| Affichage | **Champ de saisie**, ou **Jauge (curseur)** bornée par la plus petite et la plus grande valeur des offres de la page |
| Unité | facultative, affichée à côté du nombre : `€`, `km`, `m`… |

C'est vous qui fixez la comparaison ; le visiteur ne saisit qu'un nombre, et lit à côté ce qu'il signifie : « au moins [ 3 ] », « entre [ 10 ] € et [ 50 ] € ». Pour un budget, pensez au champ du prix *minimum* avec **Au plus** : « au plus 50 € » retient les offres dont le prix le plus bas tient dans ce budget.

- **Saisie contrôlée** : « 9,50 » et « 9.50 » sont acceptés, les espaces de groupement ignorés (« 1 250 »). Une saisie qui n'est pas un nombre est signalée sous le champ et le critère ne s'applique pas ; rien n'est corrigé à la place du visiteur. Deux bornes inversées sont remises dans l'ordre.
- **Jauge** : laissée en butée, elle ne restreint rien. Les deux curseurs d'un intervalle ne se croisent pas. Une jauge ne sert pas l'égalité : avec **Égal à**, l'affichage repasse sur le champ de saisie.
- **Offres sans valeur** : une offre qui n'a pas ce champ est écartée dès que le critère est utilisé, comme pour tout autre critère.
- **Adresse** : une borne par paramètre, `?chambres_min=3`, `?prix_max=50`, `?distance_min=5&distance_max=10` ; l'égalité garde la clé nue, `?etoiles=4`.
- **Recherche plein texte** : les valeurs numériques n'y entrent pas — taper « 4 » ne ramène pas tous les hôtels de quatre chambres.

Le type du champ décide des nombres reconnus : `UInt`, `UFloat`, `SFloat` et `Currency`. Durées et heures (« 3:30 ») n'en font pas partie.

### Trouver le bon champ PIVOT

Aucune urn à retenir : le lien **Parcourir les champs disponibles**, sous la case Urn, ouvre le catalogue des champs lu dans le thesaurus.

- Les champs sont groupés par catégorie, avec leur libellé traduit et leur type PIVOT.
- Un champ de recherche filtre sur le libellé, l'urn ou la catégorie.
- Le sélecteur de type d'offre place en tête les types **réellement présents dans cette page**, repérés lors de la dernière construction de l'index. Ce sont les seuls dont les champs produiront des valeurs ; les autres types du thesaurus restent accessibles en dessous.
- Cliquer sur un champ remplit l'urn, reprend son libellé s'il est encore vide, et sélectionne le contrôle adapté :

| Type PIVOT | Contrôle proposé |
|---|---|
| `Boolean` | interrupteur |
| `Choice`, `HChoice` | liste déroulante |
| `MultiChoice`, `HMultiChoice` | cases à cocher |
| `UInt` | nombre à comparer, **au moins** |
| `Currency` | nombre à comparer, **au plus** |
| `UFloat`, `SFloat` | nombre à comparer, **entre deux valeurs** |
| `String`, `StringML`, `TextML`, `URL`, `EMail`, `Date`… | saisie libre |
| autres | liste déroulante |

La case Urn accepte toujours la saisie directe, avec l'autocomplétion du navigateur sur les champs déjà chargés.

Le catalogue vient du cache thesaurus : il ne coûte qu'un appel à PIVOT la première fois. Si la liste paraît incomplète après une évolution du modèle de données, réinitialisez ce cache depuis **Cache et outils**.

Un filtre sur un champ PIVOT exige la richesse **Complet** : le mode résumé ne renvoie pas les `spec`.

---

## Visites guidées

À la première ouverture de **Pages de listing**, **Ajouter une page**, **Champs affichés** et **Réglages**, une visite guidée se lance : un projecteur éclaire l'élément concerné et une bulle explique à quoi il sert.

- L'avancement est enregistré **par utilisateur** : chaque personne de l'équipe voit la visite une fois, et elle ne se rouvre pas ensuite. La visite est retenue **dès son ouverture**, pas à sa dernière étape : quitter la page en cours de route ne la fait pas revenir.
- Le bouton à côté du titre de chaque écran la rejoue à la demande.
- **PIVOT → Cache et outils → Aide** remet toutes les visites à zéro pour votre compte.
- Les touches ← et → parcourent les étapes, Échap ferme.

### La visite détaillée d'un critère

Le bloc **Critères de recherche** porte son propre lien, **Comment régler un critère ?**. Il ouvre une visite de dix étapes qui passe les cinq réglages un par un — libellé, source, urn, contrôle, clé d'URL — en expliquant ce que chacun change pour le visiteur, puis aborde les traductions, le retrait d'un critère et le rappel sur la richesse « Complet ».

Cette visite ne se lance jamais toute seule : elle répond à un clic. Et si aucun critère n'est encore présent à l'écran, elle en ajoute un d'elle-même pour avoir quelque chose à montrer.

Une étape dont la cible est absente de la page est silencieusement sautée, et le compteur s'ajuste : sur un site monolingue, par exemple, l'étape consacrée aux traductions d'un critère ne s'affiche pas. Les visites restent donc justes quel que soit l'état de l'écran.

Pour adapter les textes, ajouter une visite ou en retirer une, passez par le filtre `pivot_onboarding_tours`.

---

## Vérifier la version installée

Deux endroits l'affichent : la liste des extensions de WordPress, et la première ligne du tableau **PIVOT → Cache et outils → Diagnostic**.

Si vous ne voyez pas une nouveauté annoncée, c'est presque toujours que l'ancienne version est encore en place. Sur une installation locale, remplacez le contenu du dossier `wp-content/plugins/pivot-offres/` par celui de l'archive, plutôt que de passer par l'envoi de zip : WordPress refuse d'écraser un dossier existant. Supprimez d'abord les fichiers présents, sans quoi des restes de l'ancienne version cohabitent avec la nouvelle.

Attention : passer par **Extensions → Supprimer** exécute la désinstallation, qui efface les réglages et les pages de listing. Préférez le remplacement de fichiers.

Videz ensuite le cache de votre navigateur (Ctrl+F5) pour le JavaScript et les styles.

---

## En cas de problème à l'activation

Depuis la version 1.1.1, le plugin refuse de se charger plutôt que de provoquer une erreur fatale. Il affiche alors un encadré rouge dans l'administration qui nomme précisément le problème. Les causes possibles :

| Message | Ce qu'il faut faire |
|---|---|
| Ces noms sont déjà déclarés sur le site | Un autre plugin, votre thème, ou votre code PIVOT existant utilise déjà un des noms du plugin. Désactivez-le ou renommez ses fonctions. |
| PIVOT Offres demande PHP 7.4 ou plus récent | Demandez la mise à jour de PHP à votre hébergeur. |
| L'extension PHP « SimpleXML » (ou « libxml », « json ») est absente | Faites installer `php-xml` et `php-json` par votre hébergeur : sans elles, les réponses de PIVOT sont illisibles et le cache ne peut pas être écrit. |
| Le dossier … n'est pas accessible en écriture | Corrigez les droits du dossier nommé : `wp-content/uploads`, où sont rangés les index, ou `wp-content`, qui accueille le cache privé (`pivot-cache-private/`). |

Le plugin ne traduit aucune chaîne avant l'action `init` : il ne déclenche donc pas l'avertissement « Translation loading for the … domain was triggered too early » de WordPress 6.7, ni la cascade de « Cannot modify header information » qu'il entraîne quand l'affichage des erreurs est actif (Local, MAMP, serveur de développement).

Si l'activation aboutit mais qu'une étape d'installation a échoué (table de journal, dossier de cache, tâches planifiées), un avertissement jaune le signale et détaille l'étape en cause.

### Obtenir le message exact

Si l'écran reste blanc, ajoutez ceci dans `wp-config.php`, juste avant la ligne `/* That's all, stop editing! */` :

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Rechargez la page d'activation : l'erreur complète, avec le fichier et le numéro de ligne, apparaît dans `wp-content/debug.log`. WordPress envoie aussi cette information par courriel à l'adresse d'administration du site.

---

## Multilingue

Le plugin **ne gère pas les langues lui-même**. Il suit l'extension de traduction installée sur le site, quelle qu'elle soit. Sans extension de traduction, le site est monolingue : une seule langue, une seule URL par page, aucun préfixe inventé.

Ce qui reste toujours vrai, dans les deux cas : **les libellés des offres viennent de PIVOT**, qui les fournit en français, néerlandais, anglais et allemand. Une offre en cache contient les quatre ; la langue n'est choisie qu'à l'affichage.

### Extensions reconnues

WPML, Polylang, TranslatePress et Weglot sont détectés sans configuration. Le plugin leur demande la liste des langues, la langue par défaut, la langue courante et l'URL de chaque page.

Pour les URL, il procède par ordre : il demande d'abord la conversion à l'extension, puis, si elle refuse — WPML ne convertit que les adresses correspondant à un contenu WordPress, et les nôtres n'en sont pas —, il lit sa configuration d'URL et applique lui-même la règle. Les quatre formats de WPML sont couverts : répertoires, répertoire pour la langue par défaut, domaine par langue, langue en paramètre. Polylang de même.

Pour toute autre extension, quatre filtres suffisent :

```php
add_filter( 'pivot_site_languages',   fn() => array( 'fr', 'nl' ) );
add_filter( 'pivot_default_language', fn() => 'fr' );
add_filter( 'pivot_current_language', fn( $code ) => ma_langue_courante() );
add_filter( 'pivot_language_url',     fn( $url, $path, $lang ) => mon_url( $path, $lang ), 10, 3 );
```

Le premier suffit à activer le mode multilingue ; le quatrième n'est nécessaire que si votre extension ne réécrit pas déjà les liens dans la page.

### Langues hors PIVOT

Un site publié en espagnol garde ses pages espagnoles : elles existent, elles sont indexées, mais leurs contenus reprennent la langue par défaut, puisque PIVOT ne fournit pas cette langue. L'écran des réglages signale ces langues d'un repère orange.

### Vous installez une extension de traduction après coup

C'est prévu. Le plugin garde une empreinte de la configuration linguistique. Dès qu'elle change — extension installée, langue ajoutée ou retirée, langue par défaut modifiée — il :

- marque tous les index comme périmés, pour qu'ils soient reconstruits par langue ;
- demande un rafraîchissement des permaliens ;
- affiche un message dans l'administration qui nomme l'extension détectée, liste les langues, et rappelle ce qu'il vous reste à faire.

Ce qu'il vous reste à faire, précisément : traduire le titre et l'URL de chaque page de listing (section **Traductions** de l'écran d'édition), et le libellé de vos critères. Rien n'est perdu : ce qui n'est pas traduit reprend la langue par défaut, le site reste cohérent en attendant.

### Surcharger la traduction d'un critère

Oui, et c'est là que ça se passe. Chaque critère a un repli **Traductions de ce critère** qui apparaît dès que le site est multilingue :

- un **libellé par langue**, pour remplacer le titre du filtre ;
- des **traductions de valeurs**, une ligne `valeur|texte` par correction.

La colonne de gauche attend la clé stable de la valeur, listée sous **Valeurs disponibles pour ce critère** après la première construction de l'index. Une clé stable ne change pas d'une langue à l'autre : un lien filtré (`?province=namur`) reste valable dans toutes les versions du site.

Ces surcharges priment sur ce que renvoie PIVOT. Elles ne servent qu'à corriger une traduction absente ou inadaptée — dans le cas courant, laissez PIVOT faire.

### Vérifier

**PIVOT → Cache et outils → Diagnostic** indique l'extension détectée, les langues publiées, et l'URL d'exemple générée pour chacune. Deux adresses identiques y sont signalées : cela veut dire que votre extension ne distingue pas les URL fabriquées par le plugin, et le message vous indique quoi faire. Dans ce cas une seule balise `hreflang` est publiée, plutôt que plusieurs identiques.

---

## URL des fiches détail

Une fiche est publiée à l'adresse `https://exemple.be/details/CODEPIVOT&type=IDTYPE`, par exemple `/details/CHB-01-000RV1&type=3`. Sur un site multilingue, le préfixe de langue vient de l'extension de traduction : `/nl/details/CHB-01-000RV1&type=3`.

Le plugin ne fait **aucune redirection** :

| Adresse demandée | Résultat |
|---|---|
| `/details/CHB-01-000RV1&type=3` | fiche servie |
| `/details/CHB-01-000RV1` (sans le type) | fiche servie |
| `/details/CODE-INCONNU&type=3` | 404 |

Seul le code sert à retrouver l'offre, en majuscules ou en minuscules. Les deux formes de code de PIVOT sont reconnues : avec tirets (`CHB-01-000RV1`) et avec soulignés (`CGT_0001_00000087`). Quand l'adresse demandée n'a pas de type, ou pas le bon, la fiche s'affiche quand même, et sa balise canonique indique l'adresse avec le type réel de l'offre.

WordPress ajoute d'habitude une barre oblique finale aux adresses par une redirection 301. Le plugin la désactive sur les fiches : `/details/CODE&type=3` reste tel quel.

**En venant d'une version antérieure à la 2.6.0**, les fiches étaient publiées sous `/offre/nom-de-loffre-CODE/`. Ces adresses renvoient désormais une 404. La mise à jour supprime la table de redirections, les réglages d'URL et le registre des adresses, puis reconstruit les index pour que les vignettes pointent vers `/details/`.

---

## Référencement

- Titre, méta description et canonique sur les listes et les fiches ; `rel="prev"`/`rel="next"` sur les pages paginées.
- Open Graph et Twitter Card.
- JSON-LD : `ItemList` sur les listes, type dérivé du type d'offre PIVOT (`Hotel`, `Campground`, `Restaurant`, `TouristAttraction`…) sur les fiches, plus un `BreadcrumbList`.
- Micro-données `itemprop` dans le HTML des vignettes et des fiches.
- Une offre introuvable renvoie un vrai 404, jamais une page vide indexable.
- Si Yoast SEO est actif, la canonique est alignée automatiquement.

---

## Insérer des offres dans une page ou un article

Le shortcode `[pivot_offres]` pose une liste de vignettes dans un contenu WordPress ordinaire : ni carte, ni critères, rien à manipuler pour le visiteur. C'est l'usage éditorial — trois hébergements dans un article, les nouveautés en page d'accueil — par opposition aux pages de listing, qui sont des outils de recherche.

### Trois sources

```
[pivot_offres listing="hebergements" nombre="3"]
[pivot_offres query="QRY-00-0000-0000" nombre="4" tri="nom"]
[pivot_offres codes="ALD-01-00096Z,CHB-01-000RV1"]
```

- **`listing`** — reprend l'index d'une page de listing existante. C'est la forme à préférer : rien n'est redemandé à PIVOT, l'index est déjà là.
- **`query`** — interroge directement une requête pré-programmée. Seules les offres nécessaires sont demandées, et le résultat est mis en cache : un shortcode ne déclenche pas un appel par affichage de page.
- **`codes`** — une sélection nommée, dans l'ordre que vous écrivez. Pour un article qui met trois adresses en avant.

### Attributs

| Attribut | Défaut | Effet |
|---|---|---|
| `nombre` | 6 | nombre de vignettes |
| `colonnes` | 3 | de 1 à 6 ; repasse à 2 puis 1 sur petit écran |
| `tri` | `defaut` | `defaut`, `nom`, `aleatoire` |
| `filtre` | — | `province:namur\|type:hotel` — restreint sur les critères de la page ; un critère numérique prend une étendue : `chambres:3..` (au moins 3), `prix:..50` (au plus 50), `distance:5..10` |
| `titre` | — | titre affiché au-dessus |
| `lien` | `non` | `oui` ajoute un lien vers la page de listing |
| `lien_texte` | *Voir toutes les offres* | libellé de ce lien |
| `classe` | — | classe CSS supplémentaire |

### Un formulaire pour le construire

**PIVOT → Shortcode** évite d'avoir à retenir la syntaxe : vous choisissez la source, le nombre, les colonnes, l'ordre, une restriction éventuelle, et le shortcode s'écrit au fur et à mesure. Un bouton le copie, un autre affiche l'**aperçu réel** juste en dessous, sans rien perdre de ce que vous étiez en train d'essayer.

Le formulaire rappelle aussi les clés de critères disponibles pour chaque page, avec quelques-unes de leurs valeurs : c'est ce qu'il faut pour écrire `filtre="province:namur"` sans se tromper. Pour un critère numérique, il rappelle l'étendue des valeurs, `chambres : 1..165`.

Les étendues s'écrivent avec `..` et non avec `<` ou `>` : WordPress vide un attribut de shortcode qui contient un `<` sans `>` correspondant.

L'écran d'édition d'une page de listing affiche par ailleurs le shortcode correspondant, prêt à copier.

### Détails qui comptent

Les vignettes passent par le **même gabarit** que les pages de listing : si votre thème a surchargé `pivot-offres/parts/card.php`, sa version est reprise ici aussi, avec les mêmes micro-données.

Une erreur de configuration — page inexistante, source manquante — n'affiche un message qu'aux personnes qui peuvent administrer l'extension. Un visiteur ne voit rien.

---

## Robustesse des données

Les réponses de PIVOT varient d'une offre à l'autre : un champ présent ici peut manquer là. Toute lecture passe par `pivot_get()`, qui renvoie une valeur de repli plutôt qu'un avertissement PHP.

```php
$locality = pivot_get( $offer, 'address.locality' );      // null si absent
pivot_echo( $offer, 'address.zip', '<span>', '</span>' );  // n'affiche rien si absent
```

Une offre incomplète produit une fiche plus courte, jamais une erreur.

---

## Personnalisation

### Gabarits

Copiez-les dans votre thème, dans un dossier `pivot-offres/`, pour les surcharger : `listing.php`, `detail.php`, `parts/card.php`, `parts/filter-range.php`.

Un thème qui réécrit `listing.php` et ses critères doit prévoir le contrôle `range` : sans cela, un critère numérique retombe sur une liste déroulante vide. Le plus simple est de déléguer au gabarit de l'extension, qui porte les attributs `data-*` lus par le script :

```php
<?php if ( 'range' === $filter['type'] ) : ?>
	<?php Pivot_Templates::instance()->part( 'filter-range', array( 'filter' => $filter ) ); ?>
<?php endif; ?>
```

### Un gabarit par type d'offre

Une fiche d'hébergement n'a pas à ressembler à une fiche d'itinéraire. Le gabarit le plus spécifique disponible est utilisé, du plus précis au plus général :

```
pivot-offres/detail-chb-01-000rv1.php     une offre nommément désignée
pivot-offres/detail-type-10.php           un type PIVOT
pivot-offres/detail-evenement.php         une famille
pivot-offres/detail.php                   le gabarit commun
```

Les vignettes suivent la même règle : `parts/card-type-10.php`, `parts/card-evenement.php`, `parts/card.php`.

Vous n'écrivez que ce que vous voulez distinguer, le reste est hérité.

#### La vignette et le JavaScript

Une page de listing est rendue deux fois : par le serveur d'abord, puis par le navigateur, qui réécrit la grille dès que l'index arrive — y compris sur la première page, et à chaque recherche, filtre ou changement de page.

Dès qu'un gabarit de vignette est présent dans votre thème, **le plugin l'exécute au moment de construire l'index et embarque le HTML obtenu**, dans chaque langue. Le navigateur le repose tel quel : votre vignette est la même partout, sans qu'il faille l'écrire une seconde fois en JavaScript.

Ce pré-rendu ne se déclenche que pour les types dont le gabarit sort du gabarit commun. Un site qui n'a rien surchargé garde exactement l'index d'avant. À l'inverse, une vignette surchargée pour tous les types **multiplie l'index par trois environ** (× 2 après compression) : c'est le prix d'un rendu fidèle, et il ne se paie que là où vous l'avez demandé.

Le rendu JavaScript de `assets/js/pivot-listing.js` ne sert plus que pour le gabarit commun, dont il reproduit la structure. Dès que votre thème fournit une vignette, c'est elle qui est rendue, des deux côtés.

Conséquence pratique : **un gabarit de vignette modifié ne se voit qu'après reconstruction de l'index**, alors qu'un gabarit de fiche s'applique immédiatement. Videz l'index depuis **Cache et outils** pendant que vous travaillez dessus.

### Les familles

Les familles évitent d'écrire un gabarit par type : PIVOT en compte une centaine. Ce sont **celles que PIVOT déclare lui-même**, lues par le service `thesaurus/family` : hébergement, découverte et divertissement, itinéraires… Rien n'est deviné.

Le slug qui entre dans le nom du gabarit dérive du **libellé français** de la famille, quelle que soit la langue de la page. Autrement dit `detail-hebergement.php` s'applique aussi bien à `/hebergements/` qu'à `/nl/verblijven/` : vous écrivez un gabarit, pas un par langue. Si une famille n'a pas de libellé français, son urn sert de slug — `detail-fam-1.php` — plutôt qu'un libellé d'une autre langue, qui rendrait le nom du fichier instable.

**PIVOT → Types d'offres** liste les types réellement rencontrés dans vos pages. Pour chacun : sa famille, les gabarits cherchés — fiche et vignette, du plus précis au plus général — et **celui qui est réellement en service**, dans votre thème ou dans le plugin. C'est l'écran à ouvrir pour savoir où écrire.

La famille reste modifiable au cas par cas. Seules ces **corrections** sont enregistrées : un type laissé sur la famille de PIVOT continue de la suivre, y compris si PIVOT le reclasse plus tard.

**Si le service `family` ne répond pas**, aucune famille n'est connue et tous les types passent par le gabarit commun. Aucune famille de remplacement n'est inventée : des slugs maison ne correspondraient à aucun de vos gabarits, et le rattachement serait faux sans que rien ne le signale. L'écran le dit et refuse d'enregistrer, pour ne pas figer ce vide dans la configuration.

Par le code : `pivot_type_family`, `pivot_type_families`, `pivot_template_hierarchy`.

### Des champs en plus sur certaines vignettes

Une vignette d'événement a besoin des dates ; une vignette d'hébergement, de la capacité. Ces champs doivent **voyager avec l'index** : la pagination et la recherche se faisant dans le navigateur, une vignette ne peut pas interroger PIVOT au moment de s'afficher.

Cela se déclare dans le code, à côté du gabarit qui s'en sert :

```php
add_filter( 'pivot_card_fields', function ( $fields, $type_id ) {
	if ( 'evenement' === Pivot_Types::family( $type_id ) ) {
		$fields[] = 'urn:obj:date';
		$fields[] = 'urn:fld:lieuevt';
	}
	return $fields;
}, 10, 2 );
```

Les valeurs arrivent dans `$item['x']`, sous l'urn privée de son préfixe :

```php
$dates = pivot_get( $item, 'x.date.values', array() );  // une entrée par période
$lieu  = pivot_get( $item, 'x.lieuevt.value' );
```

Modifier ce filtre change ce que contient l'index : reconstruisez-le pour que les vignettes en profitent.

Ce filtre **transporte la donnée, il n'affiche rien**. Le gabarit commun ignore `$item['x']` : c'est à votre gabarit de famille de décider ce qui se voit et où. L'écran **Types d'offres** rappelle, pour chaque type, les champs déclarés — utile quand une donnée manque, comme quand une donnée embarquée ne se voit nulle part.

#### Les champs structurés, et les dates en particulier

Un champ peut être un **objet** : `urn:obj:date` ne porte pas de valeur propre, ses dates et ses heures vivent dans des champs enfants, et l'objet peut se répéter — plusieurs périodes pour un même événement.

Le plugin descend dans ces objets et assemble une phrase lisible plutôt qu'une suite d'étiquettes :

```
26/08/2026
Du 01/09/2026 au 05/09/2026, de 10:00 à 18:00, Fermé le lundi
Du 12/12/2026 au 20/12/2026          (reconstruit depuis l'intervalle consolidé)
```

Chaque période est une entrée de `values` ; `value` en donne la concaténation.

### Gabarits d'exemple

`templates/examples/` contient deux fichiers commentés qui ne sont jamais chargés : `card-evenement.php` et `detail-hebergement.php`. Copiez-les dans `pivot-offres/` de votre thème et renommez-les pour qu'ils s'appliquent.

### Champs écartés : deux niveaux, à ne pas confondre

**Écartés partout.** Les **filtres de catégorisation** — les champs dynamiques par lesquels chaque opérateur marque ses offres — et les **filtres Cirkwi** disparaissent du catalogue de champs, des suggestions de critères, des fiches et des vignettes. Ils ne sont donc pas non plus filtrables. Ils servent au classement interne et à l'export, pas à décrire une offre.

La reconnaissance se fait sur le drapeau `dynamic` du thesaurus, sur la catégorie `urn:cat:filtre`, et sur les fragments `filtcat` et `cirkwi` dans l'urn. Les champs dépréciés sont écartés de la même façon. Pour en ajouter : le filtre `pivot_excluded_urn_patterns`.

**Masqués au visiteur, mais toujours filtrables.** D'autres champs n'ont rien à montrer à un visiteur — identifiants internes, statut juridique, données de gestion, ou champs déjà affichés ailleurs sur la fiche — tout en restant de bons critères de recherche. Ceux-là restent dans le catalogue et disparaissent seulement de l'écran.

**Cette liste se règle depuis l'administration.** L'écran **PIVOT → Champs affichés** liste les champs réellement rencontrés dans vos pages, sur trois niveaux — catégorie, sous-catégorie, champ — avec une case à chacun. Décocher masque, recocher rétablit, y compris un champ que le plugin masque par défaut.

La case d'une catégorie commande tous ses champs et **n'enregistre qu'une seule règle**. Pour exprimer « tout sauf celui-ci » : décochez la catégorie, puis recochez le champ à garder. Une zone de recherche filtre la liste, et un bouton efface tous les réglages d'un coup.

Seuls les **écarts** sont enregistrés : un champ auquel personne n'a touché continue de suivre les règles livrées, même si une mise à jour les modifie. La mention « réglé ici » signale les champs que vous avez déplacés.

Les champs écartés partout y figurent aussi, en lecture seule et sans case : ils ne sont ni affichables ni filtrables, et les voir évite de les chercher ailleurs.

**Après un changement, reconstruisez les index.** Les fiches détail suivent immédiatement, mais les vignettes et les descriptifs des listings sont écrits dans les fichiers d'index.

Les règles restent également modifiables par code, et **le code a le dernier mot** : vos réglages sont fusionnés dans les listes avant que les filtres PHP s'appliquent.

| Règle | Filtre | Ce qui est masqué |
|---|---|---|
| urn exacte | `pivot_hidden_urns` | `nomreco`, `statjur`, `class:title`, `class:value`, `class:superior`, `idautor`, `dateech`, `hsu`, `url`, `copyr`, `nomofr`, `codecgt` |
| arbre entier | `pivot_hidden_urn_trees` | `urn:cat:ident`, `urn:cat:accueil:attest`, `urn:cat:filtre`, `urn:cat:cirkwi`, `urn:cat:link:contact`, `urn:cat:qrcode`, `urn:cat:hist`, `urn:cat:xml`, `urn:cat:note` |
| arbre sauf exceptions | `pivot_hidden_tree_exceptions` | sous `urn:cat:descmarket:descmarket`, tout sauf `urn:fld:descmarket` |

Un arbre se compare sur l'urn du champ **et** sur sa catégorie et sa sous-catégorie. La correspondance s'arrête aux frontières de segment : `urn:cat:notoriete` ne tombe pas sous la règle `urn:cat:note`.

`urn:cat:link:contact` est masqué **du tableau des informations**, pas de la fiche : téléphone, courriel et site ont leur propre bloc dans `detail.php`, qui lit les champs directement. La règle supprime le doublon, pas le contact.

Pour masquer un champ de plus, sans toucher au code :

```php
add_filter( 'pivot_hidden_urn_trees', function ( $trees ) {
	$trees[] = 'urn:cat:comptabilite';
	return $trees;
} );
```

### Champs dont l'urn porte la langue

La plupart des champs portent leurs traductions dans le thesaurus, sous une urn unique. **Quelques-uns non** : PIVOT publie une urn par langue, préfixée.

```
urn:fld:descmarket        français (forme nue)
nl:urn:fld:descmarket     néerlandais
en:urn:fld:descmarket     anglais
de:urn:fld:descmarket     allemand
```

C'est le cas des descriptifs, de la dénomination `urn:fld:nomofr` et de l'intitulé des médias. Le plugin en fait **un seul champ à quatre versions** : la fiche n'en montre qu'une, celle de la langue lue, avec repli sur la forme française quand la traduction manque.

Deux conséquences pratiques :

- **Vos règles d'exclusion s'écrivent une fois.** `urn:fld:nomreco` couvre aussi `nl:urn:fld:nomreco` : la comparaison se fait toujours sur l'urn débarrassée de son préfixe. Idem pour les gabarits, qui reçoivent l'urn nue dans `$row['urn']`.
- **La langue prime sur l'ordre des champs.** Le descriptif essaie toutes les urns de la langue demandée avant les formes nues. Sans cela, dès qu'on déclare plusieurs urns par `pivot_description_urns`, la première remplie en français gagnerait contre la deuxième remplie en néerlandais.

### Styles

`assets/css/pivot.css` est volontairement discret et pilotable par variables :

```css
.pivot-listing, .pivot-detail {
	--pivot-accent: #0b5d3b;
	--pivot-radius: 0;
	--pivot-card-min: 300px;
}
```

### Crochets PHP

| Crochet | Usage |
|---|---|
| `pivot_excluded_urn_patterns` | fragments d'urn écartant un champ de partout, filtrage compris |
| `pivot_hidden_urns` | urns masquées au visiteur, mais toujours filtrables |
| `pivot_hidden_urn_trees` | arbres masqués au visiteur, mais toujours filtrables |
| `pivot_hidden_tree_exceptions` | arbre masqué => urns qu'on y conserve |
| `pivot_description_urns` | urns essayées pour le descriptif de vignette et de fiche (par défaut `urn:fld:descmarket` seul) |
| `pivot_name_urns` | urns essayées pour la dénomination traduite d'une offre |
| `pivot_site_languages` | déclarer les langues du site |
| `pivot_default_language` | déclarer la langue par défaut |
| `pivot_current_language` | déclarer la langue de la requête en cours |
| `pivot_language_url` | fournir l'URL d'une page dans une langue |
| `pivot_hreflang_map` | codes hreflang publiés (fr-BE, nl-BE…) |
| `pivot_locale_map` | correspondance langue PIVOT / locale WordPress |
| `pivot_index_record` | enrichir la fiche neutre d'une offre avant traduction |
| `pivot_grouped_specs` | réorganiser les blocs de la fiche |
| `pivot_index_item` | enrichir une entrée d'index, avant le rendu de la vignette |
| `pivot_card_fields` | champs supplémentaires embarqués dans les vignettes d'un type |
| `pivot_type_families` | ajouter ou renommer des familles |
| `pivot_type_family` | forcer la famille d'un type d'offre |
| `pivot_template_hierarchy` | ajouter ou réordonner les gabarits candidats |
| `pivot_listing_index` | modifier l'index complet avant écriture |
| `pivot_offer_schema` | ajuster le JSON-LD d'une offre |
| `pivot_schema_type_map` | correspondance type PIVOT → type schema.org |
| `pivot_field_control_map` | contrôle de filtre proposé pour chaque type de champ |
| `pivot_onboarding_tours` | étapes des visites guidées |
| `pivot_suggestion_ignored_urns` | champs à ne jamais proposer en critère |
| `pivot_image_url` | réécrire l'URL des images (CDN, proxy…) |
| `pivot_admin_capability` | droit requis pour administrer l'extension |
| `pivot_site_root` | racine utilisée pour construire les URL du plugin |
| `pivot_private_cache_dir` | emplacement du cache privé, hors de l'arborescence servie par le Web |
| `pivot_sslverify` | vérification du certificat lors des appels à PIVOT (`true` par défaut) |
| `pivot_logs_max_rows` | nombre maximal de lignes conservées dans le journal (50 000 par défaut) |
| `pivot_cache_flushed` | action déclenchée après une purge de cache |
| `pivot_purge_logs` | action à déclencher (`do_action`) pour purger le journal sans attendre la maintenance quotidienne |

**À vérifier lors de la mise en service** : le descriptif par défaut est `urn:fld:descmarket`, seul. Les urns de téléphone, de courriel et de site web varient selon les types d'offres et les organismes. Consultez la structure logique de vos types (`/thesaurus/typeofr/{id}`) et ajoutez à `pivot_description_urns` si vos données portent d'autres niveaux de descriptif. Inutile d'y déclarer les variantes traduites : donnez l'urn nue, les versions préfixées par la langue sont trouvées seules.

```php
add_filter( 'pivot_description_urns', function () {
	return array( 'urn:fld:descmarket', 'urn:fld:desccourt' );
} );
```

---

## Journal

**PIVOT → Journal** liste les appels au webservice : service, URL, code HTTP, durée, poids, statut de cache. La clé `ws_key` n'y apparaît jamais.

Quatre niveaux, du plus verbeux au plus discret : *tout* (y compris les lectures en cache), *appels réseau et erreurs*, *avertissements*, *erreurs seulement*. En production, **Erreurs seulement** suffit et garde la table légère.

La rétention est réglable de 1 à 90 jours ; une tâche quotidienne supprime les entrées plus anciennes.

---

## Structure

```
pivot-offres/
├── pivot-offres.php              amorçage, activation, désactivation, reprises de version
├── uninstall.php                 nettoyage complet
├── includes/
│   ├── preflight.php             contrôles avant chargement : PHP, extensions, collisions, dossiers
│   ├── helpers.php               pivot_get(), réglages, URL d'images
│   ├── class-pivot-i18n.php      langues, détection, ponts WPML et Polylang
│   ├── class-pivot-cache.php     cache fichier (aucune offre en base)
│   ├── class-pivot-logger.php    journal et rétention
│   ├── class-pivot-client.php    HTTP, paramètres matriciels, ws_key
│   ├── class-pivot-parser.php    XML PIVOT → tableaux normalisés
│   ├── class-pivot-thesaurus.php thesaurus en cache long
│   ├── class-pivot-listings.php  registre des pages de listing
│   ├── class-pivot-repository.php lecture des offres et des requêtes
│   ├── class-pivot-index-builder.php construction de l'index client
│   ├── class-pivot-suggestions.php  critères déduits d'un échantillon d'offres
│   ├── class-pivot-fields.php    lecture des champs, exclusions, objets date
│   ├── class-pivot-types.php     familles de types lues dans le thesaurus
│   ├── class-pivot-rewrites.php  URL de listing et fiches /details/
│   ├── class-pivot-rest.php      livraison de l'index, progression
│   ├── class-pivot-seo.php       titres, canoniques, JSON-LD
│   ├── class-pivot-templates.php rendu et aides d'affichage
│   ├── class-pivot-shortcodes.php shortcode d'insertion éditoriale
│   ├── class-pivot-onboarding.php visites guidées
│   └── class-pivot-cron.php      tâches planifiées
├── admin/                        réglages, pages de listing, types d'offres, champs affichés, shortcode, outils, journal
├── templates/                    gabarits surchargeables, et exemples dans examples/
├── languages/                    .pot et catalogues nl, de, en
└── assets/                       CSS, JavaScript, et Leaflet dans vendor/
```

---

## Points d'attention

- **WP-Cron** : si votre site le désactive (`DISABLE_WP_CRON`), déclenchez la reconstruction par un cron système : `wp cron event run --due-now`.
- **Fichier statique** : l'index est servi depuis `uploads`. Si votre configuration ne sert pas ce dossier directement, basculez **Livraison de l'index** sur **Route REST**.
- **Taille de l'index** : une requête de plusieurs milliers d'offres produit un JSON de plusieurs mégaoctets, téléchargé entièrement par le visiteur. Au-delà de ~2 000 offres par page, préférez plusieurs pages de listing plus ciblées. Un fichier est écrit **par langue** : quatre langues multiplient l'espace disque occupé, pas le poids téléchargé par le visiteur.
- **Vignettes surchargées et taille de l'index** : un gabarit de vignette dans votre thème fait embarquer le HTML rendu dans l'index, pour les types concernés. Mesuré sur 2 000 offres : 1,1 Mo → 3,5 Mo bruts, 55 Ko → 127 Ko compressés. Surchargez la vignette des familles qui en ont besoin plutôt que le gabarit commun, et le surcoût reste proportionné.
- **Après un déploiement** : réinitialisez le cache du thesaurus. Le filtrage par clé change la structure renvoyée, et les familles en dépendent.
- **Ajout d'une langue** : ajouter une langue dans l'extension de traduction périme tous les index. Ils se reconstruisent d'eux-mêmes, mais lancez-les depuis **Cache et outils** si vous voulez que la nouvelle version soit disponible immédiatement.
- **Une clé par opérateur** : PIVOT n'autorise qu'un opérateur touristique par clé, et désactive une clé utilisée depuis plusieurs adresses IP.
- **Leaflet** et Leaflet.markercluster sont livrés avec le plugin, dans `assets/vendor/`, et servis par le site : aucun appel à un CDN. La marche à suivre pour changer de version est dans `assets/vendor/README.md`.
