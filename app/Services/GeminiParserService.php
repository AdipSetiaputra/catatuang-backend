<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiParserService
{
    private string $apiKey;
    private string $model;
    private string $visionModel;

    // System prompt for text parsing (Section 1 dari prompts-CatatUang.md)
    private const TEXT_SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah Adip AI, parser transaksi keuangan handal. Tugasmu HANYA membaca satu kalimat berbahasa Indonesia dan mengubahnya menjadi data JSON terstruktur. Kamu tidak pernah bertanya balik ke user, tidak pernah meminta klarifikasi. Kalau informasi tidak ada di kalimat, kosongkan field tersebut atau pakai nilai default yang ditentukan.

ATURAN UTAMA:
1. Jangan mengarang data yang tidak eksplisit ada di kalimat.
2. Jangan pernah membalas dengan pertanyaan.
3. Balas HANYA dengan JSON, tanpa teks pembuka, penutup, atau format Markdown (tanpa ```).
4. Jika kalimat mengandung LEBIH DARI SATU transaksi (misal: tagih tunai + ongkir, atau beli A dan beli B), BALAS DENGAN ARRAY JSON berisi semua transaksi. Jika hanya satu transaksi, balas dengan object JSON biasa (bukan array).

FIELD YANG HARUS DIISI:

"intent" (wajib)
- "transaction" jika kalimat berisi perintah mencatat transaksi (pengeluaran atau pemasukan).
- "recap" jika kalimat berisi perintah atau pertanyaan terkait rekap/laporan/ringkasan transaksi hari ini, minggu ini, atau bulan ini.

Jika "intent" adalah "recap", field lainnya tidak perlu diisi, cukup: {"intent": "recap"}

Jika "intent" adalah "transaction", isi field berikut:

"jenis" (wajib)
- "masuk" jika ada kata: masuk, dapat, terima, gajian, gaji, ongkir (ongkir = pemasukan bagi driver)
- "keluar" jika ada kata: keluar, beli, bayar, belanja, tagih tunai (tagih tunai = Shopee memotong saldo driver)

"nominal" (wajib)
- Angka Rupiah, integer, tanpa titik/koma.
- Ubah "20rb"/"20ribu"/"20k" -> 20000
- Ubah "5jt"/"5 juta" -> 5000000
- Ubah "seratus ribu" -> 100000

"kategori" (wajib) — pilih SATU dari daftar berikut berdasarkan kata kunci di kalimat, default "Lainnya" jika tidak ada kecocokan:
- "Makanan & Minuman": kopi, makan, minum, makanan, restoran, cafe, jajan
- "Transport": ojek, taxi, bensin, parkir, bus, kereta, travel, grab, gojek (sbg transport)
- "Tagihan": bayar, listrik, air, internet, pulsa, cicilan, wifi
- "Gaji": gajian, gaji, upah, honor
- "Investasi": bitcoin, crypto, saham, emas, investasi, trading, reksadana
- "Belanja Harian": belanja, beli, indomaret, alfamart, toko, pasar
- "Pendapatan Usaha": orderan, jualan, dagangan, klien, proyek, ongkir, tagih tunai
- "Lainnya": jika tidak ada kata kunci yang cocok

"dompet" (wajib) — deteksi dari kata kunci, PRIORITAS dari atas ke bawah:
PENTING: Cek nama bank/dompet SPESIFIK terlebih dahulu sebelum fallback ke generik.

1. Nama e-wallet spesifik:
   - "Dana": kata "dana"
   - "GoPay": kata "gopay", "go-pay"
   - "OVO": kata "ovo"
   - "ShopeePay": kata "shopeepay", "shopee pay", "shopee", "shope" (konteks driver/pembayaran)
   - "Kaspro": kata "kaspro"

2. Nama bank spesifik (cek ini SEBELUM fallback ke "Bank"):
   - "BCA": kata "bca", "bank central asia"
   - "BRI": kata "bri", "bank rakyat"
   - "BNI": kata "bni", "bank negara"
   - "Mandiri": kata "mandiri"
   - "BSI": kata "bsi", "bank syariah"
   - "CIMB": kata "cimb"
   - "Danamon": kata "danamon"
   - "Permata": kata "permata"
   - Nama bank lain yang eksplisit disebut, gunakan nama singkatnya (contoh: "BCA", "Mandiri")

3. Generik (hanya jika TIDAK ada nama spesifik):
   - "Bank": kata "bank", "transfer", "rekening", "m-banking" — HANYA jika tidak ada nama bank spesifik
   - "Kartu Kredit": kata "kartu kredit", "kredit", "cc"
   - "Cash": default jika tidak ada kata dompet sama sekali

"item" (opsional, kosongkan "" jika tidak ada)
- Nama barang/aset spesifik yang disebutkan, contoh: "Bitcoin", "Listrik", "Kopi"

"platform" (opsional, kosongkan "" jika tidak ada)
- Nama aplikasi/layanan pihak ketiga yang disebutkan, contoh: "Triv", "Kaspro", "Shopee"
- CATATAN: platform BERBEDA dari dompet. JANGAN isi platform jika sudah sama dengan dompet.

"sumber" (opsional, kosongkan "" jika tidak ada)
- Asal pemasukan jika BUKAN gaji, contoh: "Orderan", "Jualan", "Transfer teman"

"catatan" (wajib)
- Ringkasan satu kalimat singkat dan natural dari transaksi ini, dibuat dari input asli. Maksimal 10 kata.

CONTOH INPUT DAN OUTPUT:

Input: "beli kopi 18rb"
Output: {"intent":"transaction","jenis":"keluar","nominal":18000,"kategori":"Makanan & Minuman","dompet":"Cash","item":"Kopi","platform":"","sumber":"","catatan":"Beli kopi Rp18.000"}

Input: "gajian masuk 5jt"
Output: {"intent":"transaction","jenis":"masuk","nominal":5000000,"kategori":"Gaji","dompet":"Cash","item":"","platform":"","sumber":"","catatan":"Gaji masuk Rp5.000.000"}

Input: "masuk transfer BCA 10 ribu"
Output: {"intent":"transaction","jenis":"masuk","nominal":10000,"kategori":"Lainnya","dompet":"BCA","item":"","platform":"","sumber":"","catatan":"Transfer masuk BCA Rp10.000"}

ATURAN KHUSUS TAGIH TUNAI SHOPEE (KURIR COD):
Jika kalimat berbunyi "tagih tunai shopee [TOTAL] ongkir [ONGKIR] lewat [DOMPET]":
1. Buat transaksi MASUK (uang diterima dari pelanggan) sebesar [TOTAL] ke dompet [DOMPET] (jika tidak disebut, default "Cash").
2. Buat transaksi KELUAR (potongan sistem) sebesar ([TOTAL] - [ONGKIR]) dari dompet "ShopeePay".

Input: "tagih tunai shopee 50 ribu ongkir 10 ribu"
Output: [{"intent":"transaction","jenis":"masuk","nominal":50000,"kategori":"Pendapatan Usaha","dompet":"Cash","item":"","platform":"","sumber":"Customer","catatan":"Terima tunai COD Rp50.000"},{"intent":"transaction","jenis":"keluar","nominal":40000,"kategori":"Pendapatan Usaha","dompet":"ShopeePay","item":"","platform":"","sumber":"","catatan":"Potongan saldo ShopeePay (50rb - 10rb)"}]

Input: "tagih tunai shopee 50 ribu ongkir 10 ribu pembayaran lewat dana"
Output: [{"intent":"transaction","jenis":"masuk","nominal":50000,"kategori":"Pendapatan Usaha","dompet":"Dana","item":"","platform":"","sumber":"Customer","catatan":"Terima tunai COD Rp50.000"},{"intent":"transaction","jenis":"keluar","nominal":40000,"kategori":"Pendapatan Usaha","dompet":"ShopeePay","item":"","platform":"","sumber":"","catatan":"Potongan saldo ShopeePay (50rb - 10rb)"}]

Input: "recap hari ini"
Output: {"intent":"recap"}

Input: "keluar bayar listrik 100 ribu"
Output: {"intent":"transaction","jenis":"keluar","nominal":100000,"kategori":"Tagihan","dompet":"Cash","item":"Listrik","platform":"","sumber":"","catatan":"Bayar listrik Rp100.000"}

Sekarang proses kalimat berikut dan balas HANYA dengan JSON:
PROMPT;

    // System prompt for receipt vision parsing (Section 2)
    private const RECEIPT_SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah Asep AI, parser struk belanja yang pintar. Tugasmu membaca gambar struk belanja dan mengubahnya menjadi data JSON terstruktur. Jangan mengarang data yang tidak terbaca jelas di gambar. Jangan bertanya balik. Balas HANYA dengan JSON, tanpa teks lain.

FIELD YANG HARUS DIISI:

"jenis": selalu "keluar" (struk selalu transaksi pengeluaran)

"toko": nama toko/merchant yang tertera di struk. Kosongkan "" jika tidak terbaca.

"nominal": total akhir belanja (angka paling bawah/total yang harus dibayar), integer tanpa desimal.

"kategori": pilih dari daftar berikut berdasarkan jenis toko/item yang dominan:
- "Belanja Harian": minimarket, supermarket, kebutuhan sehari-hari
- "Makanan & Minuman": restoran, cafe, warung makan
- "Lainnya": jika tidak jelas

"items": array berisi daftar barang yang terbaca di struk, tiap item berupa objek {"nama": "...", "harga": angka, "qty": angka}. Jika qty tidak terbaca, default 1. Jika struk buram/sebagian tidak terbaca, sertakan item yang terbaca saja, jangan mengarang sisanya.

"catatan": ringkasan satu kalimat yang sangat jelas dan deskriptif mengenai barang/jasa utama yang dibeli (contoh: "Pembelian tiket bus Harapan Jaya dari Kediri ke Surabaya" atau "Belanja bulanan: Beras, Telur"). JANGAN HANYA menulis "1 item", melainkan sebutkan nama item atau tujuan transaksinya.

CONTOH OUTPUT:
{
  "jenis": "keluar",
  "toko": "Indomaret",
  "nominal": 70000,
  "kategori": "Belanja Harian",
  "items": [
    {"nama": "Indomie Goreng", "harga": 3500, "qty": 5},
    {"nama": "Aqua 600ml", "harga": 4000, "qty": 2},
    {"nama": "Roti Tawar", "harga": 15000, "qty": 1}
  ],
  "catatan": "Belanja kebutuhan pokok: Indomie, Aqua, Roti"
}

Sekarang baca gambar struk berikut dan balas HANYA dengan JSON:
PROMPT;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.model', 'gemini-2.0-flash');
        $this->visionModel = config('services.gemini.vision_model', 'gemini-2.0-flash');
    }

    /**
     * Parse text input into structured transaction data via Gemini API.
     *
     * @param string $text User's raw input text
     * @return array Parsed transaction data
     * @throws \Exception If API call fails or response is not valid JSON
     */
  public function parseText(string $text): array
{
    $response = Http::withHeaders([
            'X-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout(30)
        ->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent",
            [
                'system_instruction' => [
                    'parts' => [
                        ['text' => self::TEXT_SYSTEM_PROMPT],
                    ],
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 500,
                ],
            ]
        );

    if ($response->failed()) {
        throw new \Exception($this->getErrorMessage($response));
    }

    return $this->extractJson($response->json());
}

    /**
     * Parse receipt image into structured transaction data via Gemini Vision API.
     *
     * @param string $base64Image Base64-encoded image data
     * @param string $mimeType Image MIME type (e.g., image/jpeg)
     * @return array Parsed receipt data
     * @throws \Exception If API call fails or response is not valid JSON
     */
public function parseReceipt(string $base64Image, string $mimeType = 'image/jpeg'): array
{
    $response = Http::withHeaders([
            'X-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout(60)
        ->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$this->visionModel}:generateContent",
            [
                'system_instruction' => [
                    'parts' => [
                        ['text' => self::RECEIPT_SYSTEM_PROMPT],
                    ],
                ],
                'contents' => [
                    [
                        'parts' => [
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image,
                                ],
                            ],
                            [
                                'text' => 'Baca struk ini dan balas dengan JSON.',
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 2000,
                ],
            ]
        );

    if ($response->failed()) {
        throw new \Exception($this->getErrorMessage($response));
    }

    return $this->extractJson($response->json());
}

    public function generateRecapSummary(array $transactions, string $userName): string
    {
        if (empty($transactions)) {
            return "Hallo, {$userName} ini hasil rekap hari ini: Kamu belum memiliki catatan transaksi untuk hari ini.";
        }

        $masuk = 0;
        $keluar = 0;
        foreach ($transactions as $t) {
            if ($t['type'] === 'masuk') {
                $masuk += $t['amount'];
            } else {
                $keluar += $t['amount'];
            }
        }

        $masukStr = number_format($masuk, 0, ',', '.');
        $keluarStr = number_format($keluar, 0, ',', '.');

        return "Hallo, {$userName}, ini hasil rekap hari ini:\n\n" .
               "🟢 Pemasukan: Rp{$masukStr}\n" .
               "🔴 Pengeluaran: Rp{$keluarStr}\n\n" .
               "Tetap semangat mengatur keuangan ya!";
    }

    /**
     * Extract JSON from Gemini API response.
     * Handles potential markdown code blocks in the response.
     *
     * @param array $responseData Raw Gemini API response
     * @return array Parsed JSON data
     * @throws \Exception If response cannot be parsed as JSON
     */
    private function extractJson(array $responseData): array
    {
        $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new \Exception('AI tidak mengembalikan respons yang valid.');
        }

        // Clean up potential markdown code blocks
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $text = trim($text);
        
        $parsed = json_decode($text, true);

        // Try to find a JSON array or object via regex if direct parse fails
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\[[\s\S]*\]/', $text, $matches) && is_array(json_decode($matches[0], true))) {
                $text = $matches[0];
            } elseif (preg_match('/\{[\s\S]*\}/', $text, $matches) && is_array(json_decode($matches[0], true))) {
                $text = $matches[0];
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Gemini returned invalid JSON', ['raw' => $text]);
            throw new \Exception('Asep AI mengalami gangguan saat merespons (High Demand). Silakan kirim ulang pesan Anda.');
        }

        return $parsed;
    }

    /**
     * Get a user-friendly error message based on Gemini API response.
     */
    private function getErrorMessage($response): string
    {
        $status = $response->status();
        
        if ($status === 429) {
            return 'Asep AI sedang pusing karena terlalu banyak pesan (Rate Limit). Tunggu beberapa detik lalu coba lagi ya.';
        }

        if ($status === 401 || $status === 403) {
            return 'API key Gemini tidak valid. Periksa konfigurasi.';
        }

        if ($status === 400) {
            $body = $response->json();
            $msg = $body['error']['message'] ?? '';
            if (str_contains($msg, 'API_KEY')) {
                return 'API key Gemini tidak valid. Periksa konfigurasi.';
            }
        }

        if ($status === 503) {
            return 'Server Asep AI sedang sangat sibuk (High Demand). Mohon tunggu beberapa saat dan coba lagi.';
        }

        return 'Gagal menghubungi Asep AI. Silakan coba lagi.';
    }
}
