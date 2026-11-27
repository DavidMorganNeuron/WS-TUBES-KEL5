<div align="center">

# WayFinder

**Semantic search engine destinasi wisata Sumatera Utara**
berbasis Knowledge Graph (RDF/SPARQL) dan Hybrid AI

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Apache Jena Fuseki](https://img.shields.io/badge/Apache_Jena_Fuseki-SPARQL-D22128?style=flat-square&logo=apache&logoColor=white)](https://jena.apache.org/documentation/fuseki2/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![Google Maps](https://img.shields.io/badge/Google_Maps-Platform-4285F4?style=flat-square&logo=googlemaps&logoColor=white)](https://developers.google.com/maps)
[![Gemini](https://img.shields.io/badge/Gemini-API-8E75B2?style=flat-square&logo=googlegemini&logoColor=white)](https://ai.google.dev/)

</div>

---

## Tentang Proyek

WayFinder membantu wisatawan menemukan **304+ destinasi** di Sumatera Utara (kategori Alam, Budaya, Religi, Bahari, Kuliner, dan Buatan). Seluruh data dimodelkan sebagai ontologi RDF, disimpan di **Apache Jena Fuseki**, dan diakses lewat **query SPARQL** dari backend Laravel. Proyek ini dibuat untuk Tugas Besar mata kuliah Web Semantik.

## Fitur Utama

| Fitur                              | Deskripsi                                                                                                                                                                  |
| :--------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Dashboard & Pencarian Semantik** | Pencarian berbobot (nama, wilayah, alamat, fasilitas, aktivitas), saran langsung, filter dinamis tanpa reload, dan toleransi salah ketik.                                  |
| **Peta Interaktif**                | Google Maps dengan marker per kategori, clustering, dan kartu info (foto, rating, harga tiket). Leaflet dipakai sebagai fallback.                                          |
| **Perencana Rute Dalam Aplikasi**  | Rute dihitung dari posisi GPS pengguna melalui Google Routes API, lengkap dengan mode perjalanan dan rute alternatif, tanpa pindah ke halaman Google Maps.                 |
| **Detail Destinasi**               | Halaman satu destinasi dengan metadata **RDFa** dan **Open Graph Protocol**, serta ekspor RDF (`ttl`, `rdf`, `jsonld`).                                                    |
| **WayFinder AI (Hybrid Chatbot)**  | Mesin berbasis aturan menjawab langsung dari dataset Fuseki. Pertanyaan naratif diteruskan ke **Gemini API** dengan konteks relevan yang diambil dari Fuseki oleh backend. |
| **Linked Data**                    | Tautan ke Wikidata melalui snapshot terkurasi (`database/ontology/external/`).                                                                                             |
