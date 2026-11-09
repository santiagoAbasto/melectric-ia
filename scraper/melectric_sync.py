#!/usr/bin/env python3
"""
Sincronizador del sitio público de MElectric (sólo biblioteca estándar de Python 3.10+).

Recorre el sitio (el actual o el nuevo que salga del Figma: la URL base es un parámetro), respeta robots.txt, sólo
visita hosts de la lista blanca, nunca sigue redirecciones a otro host ni entra a zonas con login, espera entre pedidos
y escribe una instantánea JSON que Laravel incorpora (páginas, PDF enlazados y productos publicados en JSON-LD).

Uso:
  python3 scraper/melectric_sync.py --base https://www.melectric.com.ar --out salida.json \
      --allow www.melectric.com.ar melectric.com.ar --max 40 --delay-ms 1000

No guarda precios aunque el sitio los publique: el precio no es conocimiento (spec §57).
"""
from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import os
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser
from datetime import datetime, timezone
from html.parser import HTMLParser

SKIP_TAGS = {"script", "style", "noscript", "svg", "nav", "footer", "header", "head", "form", "iframe"}
BLOCK_TAGS = {"p", "div", "li", "br", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "section", "article", "td", "th"}
ASSET_RE = re.compile(r"\.(jpe?g|png|gif|webp|svg|ico|css|js|woff2?|ttf|zip|rar|mp4|mp3)$", re.I)
LOGIN_RE = re.compile(r"returnurl=|/login|/account|/seguridad/detalleusuario|/seguridad/enviarmailrecuperacion|/signin|/carrito|/checkout|/cdn-cgi/", re.I)


class PageParser(HTMLParser):
    """Título, descripción, texto visible (sin menú ni pie), enlaces y bloques JSON-LD."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.title = ""
        self.description = ""
        self.links: list[str] = []
        self.jsonld: list[str] = []
        self._text: list[str] = []
        self._skip = 0
        self._in_title = False
        self._in_jsonld = False
        self._buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "script" and (a.get("type") or "").lower() == "application/ld+json":
            self._in_jsonld, self._buf = True, []
            return
        if tag in SKIP_TAGS:
            self._skip += 1
        if tag == "title":
            self._in_title = True
        if tag == "meta" and (a.get("name") or a.get("property") or "").lower() in ("description", "og:description") and not self.description:
            self.description = (a.get("content") or "").strip()
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag in BLOCK_TAGS:
            self._text.append("\n")

    def handle_endtag(self, tag):
        if tag == "script" and self._in_jsonld:
            self.jsonld.append("".join(self._buf))
            self._in_jsonld = False
            return
        if tag in SKIP_TAGS and self._skip:
            self._skip -= 1
        if tag == "title":
            self._in_title = False
        if tag in BLOCK_TAGS:
            self._text.append("\n")

    def handle_data(self, data):
        if self._in_jsonld:
            self._buf.append(data)
        elif self._in_title:
            self.title += data
        elif not self._skip:
            self._text.append(data)

    def text(self) -> str:
        raw = "".join(self._text)
        lines = [re.sub(r"[ \t ​]+", " ", l).strip() for l in raw.splitlines()]
        return "\n".join(l for l in lines if len(l) > 2)


def products_from_jsonld(blocks: list[str]) -> list[dict]:
    """Productos schema.org (sin precios): nombre, sku, mpn, marca, descripción, url, imagen."""
    out: list[dict] = []

    def walk(node):
        if isinstance(node, list):
            for n in node:
                walk(n)
        elif isinstance(node, dict):
            kind = node.get("@type")
            kinds = kind if isinstance(kind, list) else [kind]
            if "Product" in kinds:
                brand = node.get("brand")
                out.append({
                    "name": str(node.get("name") or "").strip(),
                    "sku": str(node.get("sku") or "").strip(),
                    "mpn": str(node.get("mpn") or "").strip(),
                    "brand": (brand.get("name") if isinstance(brand, dict) else brand) or "",
                    "description": str(node.get("description") or "").strip()[:2000],
                    "url": node.get("url") or "",
                    "image": (node.get("image")[0] if isinstance(node.get("image"), list) and node.get("image") else node.get("image")) or "",
                })
            for k in ("@graph", "itemListElement", "item", "hasVariant"):
                if k in node:
                    walk(node[k])

    for b in blocks:
        try:
            walk(json.loads(b))
        except (ValueError, TypeError):
            continue
    return out


def safe_url(url: str, allowed: set[str]) -> None:
    """SSRF: sólo http/https, host en la lista blanca y todas sus IP públicas."""
    p = urllib.parse.urlsplit(url)
    if p.scheme not in ("http", "https"):
        raise ValueError(f"esquema no permitido: {p.scheme}")
    if p.username or p.password:
        raise ValueError("URL con credenciales")
    host = (p.hostname or "").lower()
    if host not in allowed:
        raise ValueError(f"host fuera de la lista blanca: {host}")
    infos = socket.getaddrinfo(host, p.port or (443 if p.scheme == "https" else 80), proto=socket.IPPROTO_TCP)
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if not ip.is_global:
            raise ValueError(f"{host} resuelve a una IP no pública ({ip})")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def fetch(url: str, ua: str, timeout: int = 20, retries: int = 2) -> tuple[int, dict, bytes]:
    opener = urllib.request.build_opener(_NoRedirect)
    delay = 1.5
    for attempt in range(retries + 1):
        try:
            with opener.open(urllib.request.Request(url, headers={"User-Agent": ua, "Accept": "text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.5"}), timeout=timeout) as r:
                return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read(15 * 1024 * 1024)
        except urllib.error.HTTPError as e:
            if 300 <= e.code < 400 or e.code < 500 or attempt == retries:
                return e.code, {k.lower(): v for k, v in (e.headers or {}).items()}, b""
        except (urllib.error.URLError, TimeoutError, ConnectionError):
            if attempt == retries:
                raise
        time.sleep(delay)
        delay *= 2
    return 0, {}, b""


def normalize(base: str, href: str) -> str | None:
    href = href.strip()
    if not href or href.startswith(("mailto:", "tel:", "javascript:", "#", "whatsapp:")):
        return None
    url = urllib.parse.urljoin(base, href)
    p = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit((p.scheme, p.netloc.lower(), p.path or "/", p.query, ""))


def crawl(base: str, allowed: set[str], ua: str, max_pages: int, delay_ms: int, seeds: list[str]) -> dict:
    base = base.rstrip("/")
    started = datetime.now(timezone.utc).isoformat()
    robots = urllib.robotparser.RobotFileParser()
    status, _, body = fetch(base + "/robots.txt", ua)
    robots.parse(body.decode("utf-8", "replace").splitlines() if status == 200 else [])

    queue = [normalize(base + "/", s) for s in seeds]
    seen: set[str] = set()
    pages, pdfs, out_of_scope, errors = [], {}, [], []
    while queue and len(pages) < max_pages:
        url = queue.pop(0)
        if not url or url in seen:
            continue
        seen.add(url)
        host = (urllib.parse.urlsplit(url).hostname or "").lower()
        if host not in allowed:
            continue
        if LOGIN_RE.search(url):
            out_of_scope.append({"url": url, "reason": "zona con login: no se accede"})
            continue
        if not robots.can_fetch(ua, url):
            out_of_scope.append({"url": url, "reason": "bloqueado por robots.txt"})
            continue
        try:
            safe_url(url, allowed)
            time.sleep(delay_ms / 1000)
            status, headers, body = fetch(url, ua)
        except Exception as e:  # noqa: BLE001 — se registra y se sigue con la próxima página
            errors.append({"url": url, "error": str(e)[:300]})
            continue
        if 300 <= status < 400:
            loc = normalize(url, headers.get("location", ""))
            if loc and LOGIN_RE.search(loc):
                out_of_scope.append({"url": url, "reason": "redirige a login: no se accede"})
            elif loc and (urllib.parse.urlsplit(loc).hostname or "").lower() in allowed:
                queue.append(loc)
            else:
                out_of_scope.append({"url": url, "reason": f"redirige fuera de la lista blanca: {loc}"})
            continue
        ctype = headers.get("content-type", "")
        if status != 200 or "text/html" not in ctype:
            if status != 200:
                errors.append({"url": url, "error": f"HTTP {status}"})
            continue
        parser = PageParser()
        parser.feed(body.decode(re.search(r"charset=([\w-]+)", ctype).group(1) if "charset=" in ctype else "utf-8", "replace"))
        text = parser.text()
        for href in parser.links:
            link = normalize(url, href)
            if not link:
                continue
            lhost = (urllib.parse.urlsplit(link).hostname or "").lower()
            if lhost not in allowed:
                continue
            path = urllib.parse.urlsplit(link).path
            if path.lower().endswith(".pdf"):
                pdfs.setdefault(link, {"url": link, "found_on": url, "label": ""})
            elif not ASSET_RE.search(path) and link not in seen:
                queue.append(link)
        pages.append({
            "url": url,
            "title": " ".join(parser.title.split()) or url,
            "description": parser.description,
            "text": text,
            "sha256": hashlib.sha256(text.encode("utf-8")).hexdigest(),
            "products": products_from_jsonld(parser.jsonld),
        })
    return {
        "base": base, "started_at": started, "finished_at": datetime.now(timezone.utc).isoformat(),
        "user_agent": ua, "pages": pages, "pdfs": list(pdfs.values()), "out_of_scope": out_of_scope, "errors": errors,
    }


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Sincroniza el sitio público de MElectric a un JSON")
    ap.add_argument("--base", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--allow", nargs="+", required=True, help="hosts permitidos")
    ap.add_argument("--max", type=int, default=40)
    ap.add_argument("--delay-ms", type=int, default=1000)
    ap.add_argument("--seed", nargs="*", default=["/"])
    ap.add_argument("--user-agent", default="MElectricAI-Crawler/0.2 (+https://www.melectric.com.ar)")
    a = ap.parse_args(argv)
    allowed = {h.lower() for h in a.allow}
    try:
        safe_url(a.base, allowed)
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"URL base no permitida: {e}"}, ensure_ascii=False))
        return 2
    result = crawl(a.base, allowed, a.user_agent, a.max, a.delay_ms, a.seed)
    tmp = a.out + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False)
    os.replace(tmp, a.out)
    print(json.dumps({"ok": True, "pages": len(result["pages"]), "pdfs": len(result["pdfs"]),
                      "products": sum(len(p["products"]) for p in result["pages"]),
                      "out_of_scope": len(result["out_of_scope"]), "errors": len(result["errors"])}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
