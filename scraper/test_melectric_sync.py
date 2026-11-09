"""Pruebas del sincronizador (stdlib): python3 -m unittest scraper/test_melectric_sync.py"""
import unittest

from melectric_sync import PageParser, normalize, products_from_jsonld, safe_url


class SyncTest(unittest.TestCase):
    def test_texto_visible_sin_menu_ni_pie(self):
        p = PageParser()
        p.feed('<html><head><title>FAQ</title><meta name="description" content="Preguntas"></head><body><nav>Menú</nav>'
               '<h2>Garantía</h2><p>La mayoría cuenta con 12 meses.</p><footer>Pie repetido</footer>'
               '<a href="/Content/Catalogos/X.pdf">PDF</a><script>var x=1</script></body></html>')
        self.assertEqual(p.title, "FAQ")
        self.assertEqual(p.description, "Preguntas")
        self.assertIn("12 meses", p.text())
        self.assertNotIn("Menú", p.text())
        self.assertNotIn("Pie repetido", p.text())
        self.assertIn("/Content/Catalogos/X.pdf", p.links)

    def test_productos_jsonld_sin_precio(self):
        blocks = ['{"@context":"https://schema.org","@graph":[{"@type":"Product","name":"Contactor DILM32","sku":"277260",'
                  '"mpn":"DILM32-10(230V50HZ,240V60HZ)","brand":{"@type":"Brand","name":"Eaton"},'
                  '"offers":{"@type":"Offer","price":"99"}}]}', '{roto']
        prods = products_from_jsonld(blocks)
        self.assertEqual(len(prods), 1)
        self.assertEqual(prods[0]["mpn"], "DILM32-10(230V50HZ,240V60HZ)")
        self.assertEqual(prods[0]["brand"], "Eaton")
        self.assertNotIn("price", prods[0])

    def test_ssrf_y_lista_blanca(self):
        for url in ("http://127.0.0.1/", "file:///etc/passwd", "http://evil.example.com/", "http://user:pw@www.melectric.com.ar/"):
            with self.assertRaises(ValueError):
                safe_url(url, {"www.melectric.com.ar", "127.0.0.1"})

    def test_normaliza_enlaces(self):
        self.assertEqual(normalize("https://www.melectric.com.ar/Blog", "/Home/Faq#x"), "https://www.melectric.com.ar/Home/Faq")
        self.assertIsNone(normalize("https://www.melectric.com.ar/", "mailto:ventas@x.com"))


if __name__ == "__main__":
    unittest.main()
