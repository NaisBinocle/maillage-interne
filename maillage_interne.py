#!/usr/bin/env python3
"""
Outil de Recommandations de Maillage Interne
Analyse la similarité entre les pages d'un site web et génère
des recommandations de liens internes sous forme de CSV.
"""

import argparse
import csv
import io
import math
import os
import re
import sys
import time
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.parse import urljoin, urlparse, urlunparse

import pandas as pd
import requests
from bs4 import BeautifulSoup
from sklearn.cluster import KMeans
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

# ─── Stop words français embarqués ───────────────────────────────────────────

FRENCH_STOP_WORDS = frozenset([
    "a", "ai", "aie", "aient", "aies", "ait", "as", "au", "aura", "aurai",
    "auraient", "aurais", "aurait", "auras", "aurez", "auriez", "aurions",
    "aurons", "auront", "aux", "avaient", "avais", "avait", "avec", "avez",
    "aviez", "avions", "avons", "ayant", "ayez", "ayons", "c", "ce", "ceci",
    "cela", "celle", "celles", "celui", "ces", "cet", "cette", "chez", "ci",
    "comme", "comment", "contre", "d", "dans", "de", "des", "dessous",
    "dessus", "deux", "devant", "dit", "doit", "donc", "dont", "du", "elle",
    "elles", "en", "encore", "entre", "est", "et", "eta", "etaient", "etais",
    "etait", "etant", "etc", "ete", "etes", "etiez", "etions", "etre", "eu",
    "eue", "eues", "eurent", "eus", "eusse", "eussent", "eusses", "eussiez",
    "eussions", "eut", "eux", "faire", "fait", "fera", "fois", "font", "fur",
    "fus", "fusse", "fussent", "fusses", "fussiez", "fussions", "fut", "ici",
    "il", "ils", "je", "juste", "l", "la", "le", "les", "leur", "leurs",
    "lors", "lui", "m", "ma", "mais", "me", "meme", "mes", "mettre", "moi",
    "moins", "mon", "mot", "n", "ne", "ni", "non", "nos", "notre", "nous",
    "on", "ont", "or", "ou", "par", "parce", "pas", "peut", "peu", "plupart",
    "plus", "pour", "pourquoi", "quand", "que", "quel", "quelle", "quelles",
    "quels", "qui", "qu", "s", "sa", "sans", "se", "sera", "serai",
    "seraient", "serais", "serait", "seras", "serez", "seriez", "serions",
    "serons", "seront", "ses", "si", "sien", "son", "sont", "sous", "soyez",
    "soyons", "suis", "sur", "t", "ta", "te", "tes", "toi", "ton", "tous",
    "tout", "toute", "toutes", "tres", "trop", "tu", "un", "une", "vos",
    "votre", "vous", "vu", "y",
])


# ─── Utilitaires ─────────────────────────────────────────────────────────────

def normaliser_url(url):
    """Normalise une URL : lowercase domaine, strip trailing /, remove fragment."""
    url = url.strip()
    if not url:
        return url
    parsed = urlparse(url)
    scheme = parsed.scheme.lower() or "https"
    netloc = parsed.netloc.lower()
    path = parsed.path.rstrip("/") if parsed.path != "/" else "/"
    if not path:
        path = "/"
    query = parsed.query
    return urlunparse((scheme, netloc, path, "", query, ""))


def detecter_encodage(filepath):
    """Essaie plusieurs encodages pour lire un fichier."""
    for enc in ("utf-8-sig", "utf-8", "latin-1", "cp1252"):
        try:
            with open(filepath, "r", encoding=enc) as f:
                f.read(4096)
            return enc
        except (UnicodeDecodeError, UnicodeError):
            continue
    return "utf-8"


def detecter_format_input(filepath):
    """Détecte si le fichier est un export Screaming Frog CSV ou une liste d'URLs."""
    enc = detecter_encodage(filepath)
    with open(filepath, "r", encoding=enc) as f:
        premiere_ligne = f.readline().strip()
    if "," in premiere_ligne or "\t" in premiere_ligne or ";" in premiere_ligne:
        sep = "\t" if "\t" in premiere_ligne else (";" if ";" in premiere_ligne else ",")
        cols = [c.strip().strip('"').strip("'") for c in premiere_ligne.split(sep)]
        cols_lower = [c.lower() for c in cols]
        if "address" in cols_lower:
            return "screaming_frog", enc, sep
    if premiere_ligne.startswith("http://") or premiere_ligne.startswith("https://"):
        return "url_list", enc, None
    return "url_list", enc, None


def charger_urls_screaming_frog(filepath, enc, sep):
    """Charge les URLs depuis un export Screaming Frog, filtre Status 200."""
    df = pd.read_csv(filepath, encoding=enc, sep=sep, on_bad_lines="skip", dtype=str)
    df.columns = [c.strip() for c in df.columns]
    col_address = None
    for c in df.columns:
        if c.lower() == "address":
            col_address = c
            break
    if not col_address:
        print("Erreur : colonne 'Address' introuvable dans le CSV.")
        sys.exit(1)

    col_status = None
    for c in df.columns:
        if c.lower() in ("status code", "status_code", "status"):
            col_status = c
            break

    if col_status:
        df[col_status] = pd.to_numeric(df[col_status], errors="coerce")
        df = df[df[col_status] == 200]

    urls = df[col_address].dropna().tolist()

    sf_data = {}
    col_map = {}
    for c in df.columns:
        cl = c.lower().strip()
        if cl == "title 1" or cl == "title":
            col_map["title"] = c
        elif cl == "h1-1" or cl == "h1":
            col_map["h1"] = c
        elif cl in ("meta description 1", "meta description"):
            col_map["meta_description"] = c

    if col_map:
        for _, row in df.iterrows():
            addr = row.get(col_address)
            if pd.isna(addr):
                continue
            url_n = normaliser_url(str(addr))
            data = {}
            for key, col in col_map.items():
                val = row.get(col)
                data[key] = str(val) if pd.notna(val) else ""
            sf_data[url_n] = data

    return [normaliser_url(u) for u in urls if isinstance(u, str) and u.strip()], sf_data


def charger_urls_liste(filepath, enc):
    """Charge les URLs depuis un fichier texte (une par ligne)."""
    with open(filepath, "r", encoding=enc) as f:
        urls = [normaliser_url(line.strip()) for line in f if line.strip()]
    return [u for u in urls if u.startswith("http")], {}


def charger_inlinks(filepath):
    """Charge un export 'All Inlinks' de Screaming Frog."""
    enc = detecter_encodage(filepath)
    df = pd.read_csv(filepath, encoding=enc, on_bad_lines="skip", dtype=str)
    df.columns = [c.strip() for c in df.columns]

    col_source = None
    col_dest = None
    for c in df.columns:
        cl = c.lower()
        if cl in ("source", "from"):
            col_source = c
        elif cl in ("destination", "to", "target"):
            col_dest = c

    if not col_source or not col_dest:
        cols_lower = [c.lower() for c in df.columns]
        if len(df.columns) >= 2 and not col_source:
            col_source = df.columns[0]
            col_dest = df.columns[1]

    liens = set()
    for _, row in df.iterrows():
        src = row.get(col_source)
        dst = row.get(col_dest)
        if pd.notna(src) and pd.notna(dst):
            liens.add((normaliser_url(str(src)), normaliser_url(str(dst))))
    return liens


# ─── Scraping ────────────────────────────────────────────────────────────────

BOILERPLATE_TAGS = {"nav", "footer", "header", "aside", "script", "style", "noscript", "form"}


def scraper_page(url, user_agent, timeout):
    """Scrape une page et extrait title, h1, meta desc, body text, liens internes."""
    headers = {"User-Agent": user_agent}
    result = {
        "url": url,
        "title": "",
        "h1": "",
        "meta_description": "",
        "body_text": "",
        "liens_internes": set(),
        "erreur": None,
    }
    try:
        resp = requests.get(url, headers=headers, timeout=timeout, allow_redirects=True)
        resp.raise_for_status()

        content_type = resp.headers.get("Content-Type", "")
        if "text/html" not in content_type and "application/xhtml" not in content_type:
            result["erreur"] = f"Type non-HTML: {content_type}"
            return result

        soup = BeautifulSoup(resp.text, "lxml")

        tag_title = soup.find("title")
        if tag_title:
            result["title"] = tag_title.get_text(strip=True)

        tag_h1 = soup.find("h1")
        if tag_h1:
            result["h1"] = tag_h1.get_text(strip=True)

        tag_meta = soup.find("meta", attrs={"name": re.compile(r"^description$", re.I)})
        if tag_meta and tag_meta.get("content"):
            result["meta_description"] = tag_meta["content"].strip()

        parsed_base = urlparse(url)
        base_domain = parsed_base.netloc.lower()

        for a_tag in soup.find_all("a", href=True):
            href = a_tag["href"].strip()
            abs_url = urljoin(url, href)
            parsed_link = urlparse(abs_url)
            if parsed_link.netloc.lower() == base_domain:
                result["liens_internes"].add(normaliser_url(abs_url))

        for tag in soup.find_all(BOILERPLATE_TAGS):
            tag.decompose()

        body = soup.find("body")
        if body:
            text = body.get_text(separator=" ", strip=True)
            text = re.sub(r"\s+", " ", text)
            result["body_text"] = text[:50000]

    except requests.RequestException as e:
        result["erreur"] = str(e)
    except Exception as e:
        result["erreur"] = str(e)

    return result


def scraper_pages(urls, user_agent, timeout, delai, concurrent_max):
    """Scrape toutes les pages en parallèle avec ThreadPoolExecutor."""
    resultats = {}
    total = len(urls)
    termine = 0

    print(f"\nScraping de {total} pages ({concurrent_max} threads, delai {delai}s)...")

    with ThreadPoolExecutor(max_workers=concurrent_max) as executor:
        futures = {}
        for i, url in enumerate(urls):
            future = executor.submit(scraper_page, url, user_agent, timeout)
            futures[future] = url
            if delai > 0 and i < total - 1:
                time.sleep(delai)

        for future in as_completed(futures):
            url = futures[future]
            try:
                res = future.result()
                resultats[normaliser_url(url)] = res
                termine += 1
                if res["erreur"]:
                    print(f"  [{termine}/{total}] ERREUR {url}: {res['erreur']}")
                else:
                    print(f"  [{termine}/{total}] OK {url}")
            except Exception as e:
                termine += 1
                print(f"  [{termine}/{total}] ERREUR {url}: {e}")
                resultats[normaliser_url(url)] = {
                    "url": url, "title": "", "h1": "",
                    "meta_description": "", "body_text": "",
                    "liens_internes": set(), "erreur": str(e),
                }

    reussies = sum(1 for r in resultats.values() if not r["erreur"])
    print(f"Scraping termine : {reussies}/{total} pages reussies.")
    return resultats


# ─── Analyse TF-IDF + Similarité ─────────────────────────────────────────────

def construire_texte_pondere(page_data):
    """Construit le texte pondéré SEO pour une page.
    title x3, h1 x2, meta x2, body x1.
    """
    title = page_data.get("title", "") or ""
    h1 = page_data.get("h1", "") or ""
    meta = page_data.get("meta_description", "") or ""
    body = page_data.get("body_text", "") or ""
    parts = []
    for _ in range(3):
        parts.append(title)
    for _ in range(2):
        parts.append(h1)
    for _ in range(2):
        parts.append(meta)
    parts.append(body)
    return " ".join(parts)


def calculer_similarite(pages_data, urls):
    """Calcule la matrice TF-IDF + cosinus similarity entre toutes les pages."""
    corpus = []
    urls_valides = []
    for url in urls:
        data = pages_data.get(url, {})
        texte = construire_texte_pondere(data)
        if texte.strip():
            corpus.append(texte)
            urls_valides.append(url)

    if len(corpus) < 2:
        print("Erreur : moins de 2 pages avec du contenu. Impossible de calculer la similarite.")
        sys.exit(1)

    print(f"\nCalcul TF-IDF sur {len(corpus)} pages...")

    vectorizer = TfidfVectorizer(
        max_features=10000,
        ngram_range=(1, 2),
        sublinear_tf=True,
        strip_accents="unicode",
        stop_words=list(FRENCH_STOP_WORDS),
        min_df=1,
        max_df=0.95,
    )
    tfidf_matrix = vectorizer.fit_transform(corpus)

    print("Calcul de la matrice de similarite cosinus...")
    sim_matrix = cosine_similarity(tfidf_matrix)

    return sim_matrix, urls_valides, vectorizer, tfidf_matrix


# ─── Clustering ──────────────────────────────────────────────────────────────

def clustering_pages(tfidf_matrix, urls_valides, vectorizer, n_clusters=None):
    """KMeans clustering des pages avec labels auto."""
    n_pages = len(urls_valides)
    if n_clusters is None:
        n_clusters = max(2, int(math.sqrt(n_pages / 2)))
    n_clusters = min(n_clusters, n_pages)

    print(f"Clustering en {n_clusters} clusters...")

    kmeans = KMeans(n_clusters=n_clusters, random_state=42, n_init=10)
    labels = kmeans.fit_predict(tfidf_matrix)

    feature_names = vectorizer.get_feature_names_out()
    cluster_labels = {}
    for i in range(n_clusters):
        center = kmeans.cluster_centers_[i]
        top_indices = center.argsort()[-5:][::-1]
        top_terms = [feature_names[idx] for idx in top_indices]
        cluster_labels[i] = " / ".join(top_terms)

    page_clusters = {}
    for url, label in zip(urls_valides, labels):
        page_clusters[url] = cluster_labels[label]

    return page_clusters, cluster_labels


# ─── Détection liens existants ───────────────────────────────────────────────

def detecter_liens_existants(pages_data, inlinks_externe=None):
    """Détecte les liens internes existants depuis les données scrapées et/ou inlinks."""
    liens = set()
    for url, data in pages_data.items():
        url_n = normaliser_url(url)
        for lien in data.get("liens_internes", set()):
            liens.add((url_n, normaliser_url(lien)))
    if inlinks_externe:
        liens.update(inlinks_externe)
    return liens


# ─── Pages orphelines ────────────────────────────────────────────────────────

def detecter_pages_orphelines(urls, liens_existants):
    """Détecte les pages qui ne reçoivent aucun lien interne."""
    pages_cibles = set()
    for _, cible in liens_existants:
        pages_cibles.add(cible)
    return [u for u in urls if u not in pages_cibles]


# ─── Mots-clés communs ──────────────────────────────────────────────────────

def extraire_mots_cles_page(page_data, n=20):
    """Extrait les N mots-clés les plus importants d'une page."""
    texte = construire_texte_pondere(page_data)
    mots = re.findall(r"\b[a-zA-ZàâäéèêëïîôùûüÿçæœÀÂÄÉÈÊËÏÎÔÙÛÜŸÇÆŒ]{3,}\b", texte.lower())
    mots = [m for m in mots if m not in FRENCH_STOP_WORDS]
    compteur = Counter(mots)
    return set(mot for mot, _ in compteur.most_common(n))


def mots_cles_communs(pages_data, url1, url2, n=5):
    """Trouve les mots-clés communs entre deux pages."""
    kw1 = extraire_mots_cles_page(pages_data.get(url1, {}))
    kw2 = extraire_mots_cles_page(pages_data.get(url2, {}))
    communs = kw1 & kw2
    return sorted(communs)[:n]


# ─── Suggestion d'ancre ─────────────────────────────────────────────────────

def suggerer_ancre(pages_data, url_source, url_cible):
    """Suggère un texte d'ancre : H1 cible > titre cible > mots-clés communs."""
    data_cible = pages_data.get(url_cible, {})
    h1 = data_cible.get("h1", "")
    title = data_cible.get("title", "")
    if h1 and len(h1) < 100:
        return h1
    if title and len(title) < 100:
        return title
    communs = mots_cles_communs(pages_data, url_source, url_cible, n=3)
    if communs:
        return ", ".join(communs)
    return ""


# ─── Génération des recommandations ─────────────────────────────────────────

def generer_recommandations(
    sim_matrix, urls_valides, pages_data, page_clusters,
    liens_existants, max_reco, seuil
):
    """Génère les recommandations de maillage interne."""
    recommandations = []
    url_to_idx = {url: i for i, url in enumerate(urls_valides)}

    print(f"\nGeneration des recommandations (max {max_reco} par page, seuil {seuil})...")

    for i, url_source in enumerate(urls_valides):
        scores = []
        for j, url_cible in enumerate(urls_valides):
            if i == j:
                continue
            score = sim_matrix[i][j]
            if score >= seuil:
                scores.append((score, url_cible))

        scores.sort(key=lambda x: x[0], reverse=True)

        for score, url_cible in scores[:max_reco]:
            lien_existe = (url_source, url_cible) in liens_existants
            communs = mots_cles_communs(pages_data, url_source, url_cible)
            ancre = suggerer_ancre(pages_data, url_source, url_cible)
            cluster_source = page_clusters.get(url_source, "")
            cluster_cible = page_clusters.get(url_cible, "")

            recommandations.append({
                "Page Source": url_source,
                "Page Cible": url_cible,
                "Score Similarite": round(score, 4),
                "Ancre Suggeree": ancre,
                "Mots-cles Communs": ", ".join(communs),
                "Lien Existant": "oui" if lien_existe else "non",
                "Cluster Source": cluster_source,
                "Cluster Cible": cluster_cible,
            })

    recommandations.sort(key=lambda x: x["Score Similarite"], reverse=True)
    return recommandations


# ─── Export CSV ──────────────────────────────────────────────────────────────

def exporter_csv(recommandations, output_path):
    """Exporte les recommandations en CSV."""
    colonnes = [
        "Page Source", "Page Cible", "Score Similarite", "Ancre Suggeree",
        "Mots-cles Communs", "Lien Existant", "Cluster Source", "Cluster Cible",
    ]
    df = pd.DataFrame(recommandations, columns=colonnes)
    df.to_csv(output_path, index=False, encoding="utf-8-sig")
    print(f"\nCSV exporte : {output_path} ({len(recommandations)} recommandations)")
    return df


# ─── Résumé console ─────────────────────────────────────────────────────────

def afficher_resume(
    urls_valides, recommandations, page_clusters, cluster_labels,
    pages_orphelines, liens_existants, sim_matrix
):
    """Affiche un résumé des résultats dans la console."""
    total_reco = len(recommandations)
    nouvelles = sum(1 for r in recommandations if r["Lien Existant"] == "non")
    pct_nouvelles = (nouvelles / total_reco * 100) if total_reco > 0 else 0

    print("\n" + "=" * 60)
    print("RESUME DES RESULTATS")
    print("=" * 60)
    print(f"  Pages analysees       : {len(urls_valides)}")
    print(f"  Recommandations       : {total_reco}")
    print(f"  Nouvelles opportunites: {nouvelles} ({pct_nouvelles:.1f}%)")
    print(f"  Liens existants       : {total_reco - nouvelles}")
    print(f"  Clusters thematiques  : {len(cluster_labels)}")
    print(f"  Pages orphelines      : {len(pages_orphelines)}")

    if pages_orphelines:
        print("\n  Pages orphelines :")
        for p in pages_orphelines[:10]:
            print(f"    - {p}")
        if len(pages_orphelines) > 10:
            print(f"    ... et {len(pages_orphelines) - 10} autres")

    print(f"\n  Clusters :")
    for cid, label in sorted(cluster_labels.items()):
        count = sum(1 for v in page_clusters.values() if v == label)
        print(f"    [{cid}] {label} ({count} pages)")

    print(f"\n  Top 5 paires les plus similaires :")
    paires = []
    n = len(urls_valides)
    for i in range(n):
        for j in range(i + 1, n):
            paires.append((sim_matrix[i][j], urls_valides[i], urls_valides[j]))
    paires.sort(key=lambda x: x[0], reverse=True)
    for score, u1, u2 in paires[:5]:
        print(f"    {score:.4f}  {u1}")
        print(f"             {u2}")

    print("=" * 60)


# ─── Main ────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Outil de recommandations de maillage interne SEO",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Exemples :
  python maillage_interne.py -i crawl.csv -o resultats.csv
  python maillage_interne.py -i urls.txt --max-reco 10 --seuil 0.15
  python maillage_interne.py -i crawl.csv --no-scrape --inlinks inlinks.csv
        """,
    )
    parser.add_argument("-i", "--input", required=True, help="Fichier d'entree (CSV Screaming Frog ou liste d'URLs)")
    parser.add_argument("-o", "--output", default="recommandations_maillage.csv", help="Fichier CSV de sortie (defaut: recommandations_maillage.csv)")
    parser.add_argument("--max-reco", type=int, default=5, help="Nombre max de recommandations par page (defaut: 5)")
    parser.add_argument("--seuil", type=float, default=0.1, help="Seuil minimum de similarite (defaut: 0.1)")
    parser.add_argument("--clusters", type=int, default=None, help="Nombre de clusters (defaut: auto)")
    parser.add_argument("--user-agent", default="MaillageInterne/1.0 (SEO Tool)", help="User-Agent pour le scraping")
    parser.add_argument("--timeout", type=int, default=10, help="Timeout des requetes en secondes (defaut: 10)")
    parser.add_argument("--delai", type=float, default=0.5, help="Delai entre requetes en secondes (defaut: 0.5)")
    parser.add_argument("--no-scrape", action="store_true", help="Ne pas scraper, utiliser les donnees du CSV Screaming Frog")
    parser.add_argument("--inlinks", default=None, help="Fichier 'All Inlinks' de Screaming Frog pour detecter les liens existants")
    parser.add_argument("--concurrent", type=int, default=5, help="Nombre de threads pour le scraping (defaut: 5)")

    args = parser.parse_args()

    if not os.path.exists(args.input):
        print(f"Erreur : fichier introuvable : {args.input}")
        sys.exit(1)

    # ─── 1. Parsing de l'input ───────────────────────────────────────────
    print(f"Chargement de {args.input}...")
    format_type, enc, sep = detecter_format_input(args.input)

    sf_data = {}
    if format_type == "screaming_frog":
        print("Format detecte : export Screaming Frog")
        urls, sf_data = charger_urls_screaming_frog(args.input, enc, sep)
    else:
        print("Format detecte : liste d'URLs")
        urls, sf_data = charger_urls_liste(args.input, enc)

    if not urls:
        print("Erreur : aucune URL trouvee dans le fichier.")
        sys.exit(1)

    urls = list(dict.fromkeys(urls))
    print(f"{len(urls)} URLs uniques chargees.")

    # ─── 2. Scraping ou utilisation des données SF ───────────────────────
    pages_data = {}
    if args.no_scrape:
        if format_type != "screaming_frog":
            print("Erreur : --no-scrape necessite un export Screaming Frog en entree.")
            sys.exit(1)
        print("Mode --no-scrape : utilisation des donnees Screaming Frog.")
        for url in urls:
            data = sf_data.get(url, {})
            pages_data[url] = {
                "url": url,
                "title": data.get("title", ""),
                "h1": data.get("h1", ""),
                "meta_description": data.get("meta_description", ""),
                "body_text": "",
                "liens_internes": set(),
                "erreur": None,
            }
    else:
        pages_data = scraper_pages(
            urls, args.user_agent, args.timeout, args.delai, args.concurrent
        )

    pages_valides = {u: d for u, d in pages_data.items() if not d.get("erreur")}
    if not pages_valides:
        print("Erreur : aucune page n'a pu etre analysee.")
        sys.exit(1)

    # ─── 3. TF-IDF + Similarité cosinus ─────────────────────────────────
    urls_pour_analyse = [u for u in urls if u in pages_valides]
    sim_matrix, urls_valides, vectorizer, tfidf_matrix = calculer_similarite(
        pages_valides, urls_pour_analyse
    )

    # ─── 4. Clustering ──────────────────────────────────────────────────
    page_clusters, cluster_labels = clustering_pages(
        tfidf_matrix, urls_valides, vectorizer, args.clusters
    )

    # ─── 5. Détection des liens existants ────────────────────────────────
    inlinks_externe = None
    if args.inlinks:
        if not os.path.exists(args.inlinks):
            print(f"Attention : fichier inlinks introuvable : {args.inlinks}")
        else:
            print(f"Chargement des inlinks depuis {args.inlinks}...")
            inlinks_externe = charger_inlinks(args.inlinks)
            print(f"  {len(inlinks_externe)} liens internes charges.")

    liens_existants = detecter_liens_existants(pages_data, inlinks_externe)
    print(f"Liens internes existants detectes : {len(liens_existants)}")

    # ─── 6. Pages orphelines ────────────────────────────────────────────
    pages_orphelines = detecter_pages_orphelines(urls_valides, liens_existants)

    # ─── 7. Recommandations ─────────────────────────────────────────────
    recommandations = generer_recommandations(
        sim_matrix, urls_valides, pages_data, page_clusters,
        liens_existants, args.max_reco, args.seuil
    )

    # ─── 8. Export CSV ──────────────────────────────────────────────────
    exporter_csv(recommandations, args.output)

    # ─── 9. Résumé console ──────────────────────────────────────────────
    afficher_resume(
        urls_valides, recommandations, page_clusters, cluster_labels,
        pages_orphelines, liens_existants, sim_matrix
    )


if __name__ == "__main__":
    main()
