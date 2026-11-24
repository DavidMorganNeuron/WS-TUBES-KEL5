<?php

namespace App\Http\Controllers;

use App\Data\Destination;
use App\Services\Ontology\DestinationRepository;
use App\Services\Ontology\TypoCorrector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotController extends Controller
{
    /**
     * Kata yang terlalu umum untuk dijadikan kata kunci pencarian konteks.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'yang', 'untuk', 'dengan', 'dari', 'saya', 'apa', 'dimana', 'bagaimana', 'rekomendasi',
        'wisata', 'tempat', 'destinasi', 'terbaik', 'dan', 'atau', 'juga', 'bisa', 'tolong',
        'the', 'and', 'for', 'with', 'what', 'where', 'how', 'recommend', 'recommendation',
        'please', 'about', 'near', 'best', 'place', 'places', 'travel', 'tourist', 'tourism',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_HINTS = [
        'alam' => 'Alam', 'nature' => 'Alam', 'gunung' => 'Alam', 'air terjun' => 'Alam',
        'danau' => 'Alam', 'hutan' => 'Alam', 'waterfall' => 'Alam', 'lake' => 'Alam',
        'budaya' => 'Budaya', 'culture' => 'Budaya', 'cultural' => 'Budaya', 'heritage' => 'Budaya',
        'museum' => 'Budaya', 'sejarah' => 'Budaya', 'history' => 'Budaya', 'adat' => 'Budaya',
        'religi' => 'Religi', 'religion' => 'Religi', 'religious' => 'Religi', 'masjid' => 'Religi',
        'gereja' => 'Religi', 'vihara' => 'Religi', 'klenteng' => 'Religi', 'church' => 'Religi',
        'ibadah' => 'Religi',
        'bahari' => 'Bahari', 'marine' => 'Bahari', 'pantai' => 'Bahari', 'beach' => 'Bahari',
        'pulau' => 'Bahari', 'island' => 'Bahari', 'laut' => 'Bahari',
        'kuliner' => 'Kuliner', 'culinary' => 'Kuliner', 'food' => 'Kuliner', 'makanan' => 'Kuliner',
        'makan' => 'Kuliner', 'jajan' => 'Kuliner',
        'buatan' => 'Buatan', 'man-made' => 'Buatan', 'theme park' => 'Buatan', 'waterpark' => 'Buatan',
        'hiburan' => 'Buatan', 'wahana' => 'Buatan', 'taman' => 'Buatan',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDATION_INTENT_HINTS = [
        'berikan', 'beri', 'rekomendasi', 'rekomendasikan', 'rekomen', 'rekomendasiin',
        'saran', 'saranin', 'kasih', 'kasi', 'kasih tau', 'spill', 'spil',
        'info', 'infokan', 'cariin', 'carikan', 'list', 'daftar',
        'mana aja', 'apa aja', 'mana saja', 'apa saja',
        'dong', 'cuy', 'min', 'guys', 'recommend', 'wisata terbaik',
    ];

    /**
     * @var array<string, string>
     */
    private const SLANG_PHRASE_MAP = [
        'kasih tau' => 'informasi',
        'mana aja' => 'mana saja',
        'apa aja' => 'apa saja',
    ];

    /**
     * @var array<string, string>
     */
    private const SLANG_WORD_MAP = [
        'yg' => 'yang',
        'gak' => 'tidak',
        'ga' => 'tidak',
        'nggak' => 'tidak',
        'ngga' => 'tidak',
        'dmn' => 'di mana',
        'dimana' => 'di mana',
        'brp' => 'berapa',
        'skrg' => 'sekarang',
        'bgt' => 'banget',
        'murmer' => 'murah',
        'healing' => 'alam',
        'foto2' => 'spot foto',
        'estetik' => 'spot foto',
        'instagramable' => 'spot foto',
        'jajan' => 'kuliner',
        'wahana' => 'buatan',
    ];

    /**
     * @var list<string>
     */
    private const FOLLOW_UP_HINTS = [
        'yang gratis', 'yang murah', 'gratis aja', 'murah aja', 'kalau ', 'kalo ',
        'gimana ', 'bagaimana ', 'tambah ', 'lagi', 'selain itu', 'ada lagi',
        'di medan', 'di karo', 'di samosir', 'di toba', 'yg gratis', 'yg murah',
    ];

    /**
     * @var list<string>
     */
    private const COMPLEX_HINTS = [
        'itinerary', 'itinerari', 'rencana perjalanan', 'susun perjalanan', 'susun rute', 'rundown',
        'bandingkan', 'perbandingan', 'membandingkan', 'compare', 'comparison', 'lebih bagus mana',
        'kenapa', 'mengapa', 'bagaimana rute', 'tips', 'cocok untuk', 'keluarga', 'anak-anak',
        'honeymoon',
    ];

    /**
     * @var list<string>
     */
    private const FREE_HINTS = ['gratis', 'free', 'tanpa tiket'];

    /**
     * @var list<string>
     */
    private const CHEAP_HINTS = ['murah', 'termurah'];

    /**
     * @var list<string>
     */
    private const TOP_HINTS = ['terbaik', 'terpopuler', 'populer', 'rating tertinggi', 'favorit'];

    /**
     * @var array<string, list<string>>
     */
    private const ATTRIBUTE_HINTS = [
        'camping' => ['camping', 'berkemah'],
        'berkemah' => ['camping', 'berkemah'],
        'foto' => ['foto', 'berfoto', 'spot foto'],
        'musholla' => ['musholla', 'mushola', 'musala'],
        'mushola' => ['musholla', 'mushola', 'musala'],
        'musala' => ['musholla', 'mushola', 'musala'],
        'parkir' => ['parkir'],
        'toilet' => ['toilet'],
        'penginapan' => ['penginapan'],
        'kuliner' => ['kuliner'],
        'berenang' => ['berenang', 'renang'],
        'renang' => ['berenang', 'renang'],
    ];

    public function chat(Request $request, DestinationRepository $repository): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:800'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,model'],
            'history.*.text' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $message = trim($validated['message']);
        $history = $validated['history'] ?? [];
        $normalizedQuery = $this->normalizeUserQuery($message, $repository);
        $localReply = $this->tryLocalReply($message, $repository, $history, $normalizedQuery);

        if ($localReply !== null) {
            return response()->json($localReply);
        }

        $apiKey = trim((string) config('services.gemini.key', ''));

        if ($apiKey === '') {
            Log::warning('GEMINI_API_KEY belum dikonfigurasi; chatbot menggunakan pencarian lokal.');

            return response()->json($this->fallbackSearchReply($normalizedQuery['corrected'], $repository));
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.gemini.timeout', 30))
                ->retry(2, 1000, static function (Throwable $exception): bool {
                    return $exception instanceof RequestException
                        && in_array($exception->response->status(), [429, 503], true);
                })
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($this->endpoint(), [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $this->systemInstruction($normalizedQuery['corrected'], $repository)],
                        ],
                    ],
                    'contents' => $this->contents($message, $history),
                    'generationConfig' => [
                        'maxOutputTokens' => 700,
                        'temperature' => 0.2,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Koneksi Gemini gagal; chatbot menggunakan pencarian lokal.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json($this->fallbackSearchReply($normalizedQuery['corrected'], $repository));
        } catch (RequestException $exception) {
            Log::warning('Gemini gagal setelah percobaan ulang; chatbot menggunakan pencarian lokal.', [
                'status' => $exception->response->status(),
            ]);

            return response()->json($this->fallbackSearchReply($normalizedQuery['corrected'], $repository));
        }

        if ($response->failed()) {
            Log::warning('Gemini mengembalikan respons gagal; chatbot menggunakan pencarian lokal.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return response()->json($this->fallbackSearchReply($normalizedQuery['corrected'], $repository));
        }

        $reply = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
            ->implode("\n");

        if (trim($reply) === '') {
            return response()->json($this->fallbackSearchReply($normalizedQuery['corrected'], $repository));
        }

        return response()->json($this->responsePayload(
            trim($reply),
            $this->matchingDestinations($normalizedQuery['corrected'], $repository->all(), $repository),
        ));
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array{clean: string, corrected: string, isRecommendationIntent: bool}|null  $normalizedQuery
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}|null
     */
    private function tryLocalReply(
        string $message,
        DestinationRepository $repository,
        array $history = [],
        ?array $normalizedQuery = null,
    ): ?array {
        $normalizedQuery ??= $this->normalizeUserQuery($message, $repository);
        $clean = $normalizedQuery['clean'];
        $corrected = $normalizedQuery['corrected'];
        $isRecommendationIntent = $normalizedQuery['isRecommendationIntent'];

        if ($clean === '') {
            return null;
        }

        if ($this->isGreeting($clean)) {
            return $this->responsePayload(
                'Halo! Saya WayFinder AI, asisten wisata Sumatera Utara. Saya bisa membantu mencari destinasi berdasarkan kategori, wilayah, harga, rating, dan informasi destinasi. Contoh: "wisata alam di Kabupaten Karo", "tempat wisata gratis", atau "info Istana Maimun".',
            );
        }

        if ($this->hasAny($corrected, ['berapa total', 'jumlah wisata', 'ada berapa', 'statistik'])) {
            return $this->statisticsReply($repository);
        }

        if ($this->isComplexNarrativeQuery($corrected)) {
            return null;
        }

        $region = $this->extractRegion($corrected, $repository);
        $types = $this->extractTypes($corrected);
        $requestedLimit = $this->extractRequestedLimit($corrected, 0);
        $limit = $this->extractRequestedLimit($corrected);
        $attributeTerms = $this->extractAttributeTerms($corrected, $repository);
        if (in_array('Kuliner', $types, true)) {
            $attributeTerms = array_values(array_diff($attributeTerms, ['kuliner']));
        }

        $mentioned = $this->findMentionedDestinations($corrected, $repository->all());

        if ($mentioned->isNotEmpty()) {
            $destination = $mentioned->first();

            return $this->destinationDetailReply($destination, $repository);
        }

        $wantsFree = $this->hasAny($corrected, self::FREE_HINTS);
        $wantsCheap = $this->hasAny($corrected, self::CHEAP_HINTS);
        $wantsTopRated = $this->hasAny($corrected, self::TOP_HINTS);
        $wantsRecommendation = $isRecommendationIntent || $this->hasAny($corrected, ['rekomendasi', 'recommend', 'wisata terbaik']);

        $isFollowUp = false;
        if ($history !== [] && ($region === null || $types === [])) {
            $isShortFollowUp = mb_strlen($clean, 'UTF-8') <= 60
                || $this->hasAny($clean, self::FOLLOW_UP_HINTS)
                || $wantsFree
                || $wantsCheap
                || $wantsTopRated
                || $attributeTerms !== []
                || $requestedLimit > 0;

            if ($isShortFollowUp) {
                $historyContext = $this->extractHistoryContext($history, $repository);

                if ($region === null && $historyContext['region'] !== null) {
                    $region = $historyContext['region'];
                    $isFollowUp = true;
                }

                if ($types === [] && $historyContext['types'] !== []) {
                    $types = $historyContext['types'];
                    $isFollowUp = true;
                }
            }
        }

        if ($types !== [] || $region !== null || $wantsFree || $wantsCheap || $wantsTopRated
            || $wantsRecommendation || $attributeTerms !== [] || $requestedLimit > 0) {
            return $this->recommendationReply(
                $repository,
                $types,
                $region,
                $wantsFree,
                $wantsCheap,
                $wantsTopRated,
                $attributeTerms,
                $limit,
                $wantsRecommendation,
                $isFollowUp,
            );
        }

        return null;
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function statisticsReply(DestinationRepository $repository): array
    {
        $destinations = $repository->all();
        $facets = $repository->facets();
        $types = collect($facets['types'] ?? [])
            ->map(fn (int $count, string $type): string => '- '.$type.': '.$count)
            ->implode("\n");

        $reply = sprintf(
            "Dataset WayFinder mencatat **%d destinasi** di **%d kabupaten/kota**. Ada %d destinasi dengan rating, dengan rata-rata rating %s.\n\nJumlah destinasi per kategori:\n%s",
            $destinations->count(),
            count($facets['kabupaten'] ?? []),
            (int) ($facets['rated_count'] ?? 0),
            isset($facets['average_rating']) && $facets['average_rating'] !== null
                ? number_format((float) $facets['average_rating'], 2, ',', '.')
                : 'belum tersedia',
            $types !== '' ? $types : '- Data kategori belum tersedia.',
        );

        return $this->responsePayload($reply, $repository->topRated(5));
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $attributeTerms
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function recommendationReply(
        DestinationRepository $repository,
        array $types,
        ?string $region,
        bool $wantsFree,
        bool $wantsCheap,
        bool $wantsTopRated,
        array $attributeTerms,
        int $limit,
        bool $wantsRecommendation = false,
        bool $isFollowUp = false,
    ): array {
        $destinations = $repository->search([
            'jenis' => $types[0] ?? null,
            'kabupaten' => $region,
            'sort' => 'rating',
        ]);

        if ($wantsFree) {
            $destinations = $destinations->filter(fn (Destination $destination): bool => $this->isFree($destination));
        }

        if ($attributeTerms !== []) {
            $destinations = $destinations->filter(
                fn (Destination $destination): bool => $this->matchesAttributeTerms($destination, $attributeTerms),
            );
        }

        if ($wantsTopRated) {
            $destinations = $destinations->filter(fn (Destination $destination): bool => ($destination->rating ?? null) !== null);
        }

        $destinations = $destinations
            ->filter(fn (Destination $destination): bool => $this->hasValidCoordinates($destination));

        if ($wantsCheap) {
            $destinations = $destinations->sortBy(fn (Destination $destination): array => [
                $destination->lowestPrice() ?? PHP_FLOAT_MAX,
                -($destination->rating ?? -1.0),
            ]);
        } else {
            $destinations = $destinations->sortByDesc(fn (Destination $destination): float => $destination->rating ?? -1.0);
        }

        $destinations = $destinations->take($limit)->values();

        if ($destinations->isEmpty()) {
            return $this->responsePayload(
                'Belum ada destinasi yang cocok dengan filter tersebut pada dataset WayFinder. Coba kategori atau wilayah lain.',
            );
        }

        $count = $destinations->count();
        $category = $types[0] ?? '';
        $subject = $category !== '' ? 'wisata '.$category : 'destinasi wisata';
        $location = $region !== null ? ' di '.$region : ' di Sumatera Utara';

        if ($wantsCheap) {
            $opening = "Ini dia **{$count} {$subject}{$location}** dengan harga tiket paling terjangkau:";
        } elseif ($wantsFree) {
            $opening = "Pas banget! Berikut **{$count} {$subject}{$location}** yang gratis tiket masuk:";
        } elseif ($isFollowUp) {
            $opening = "Siap, melanjutkan pencarian sebelumnya! Berikut **{$count} {$subject}{$location}** dengan rating tertinggi:";
        } elseif ($wantsRecommendation && $category === '' && $region === null) {
            $opening = "Tentu! Ini **{$count} rekomendasi {$subject}{$location}** dengan rating tertinggi yang layak kamu kunjungi:";
        } else {
            $opening = "Berikut **{$count} rekomendasi {$subject}{$location}** dengan rating tertinggi:";
        }

        if ($attributeTerms !== []) {
            $opening = rtrim($opening, ':').' yang sesuai dengan '.implode(', ', $attributeTerms).':';
        }

        $reply = $opening."\n\n"
            .$this->recommendationList($destinations);

        return $this->responsePayload($reply, $destinations, $limit);
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function destinationDetailReply(Destination $destination, DestinationRepository $repository): array
    {
        $types = $destination->types ?? [];
        $prices = $destination->priceLabels();
        $facilities = $destination->facilities ?? [];
        $activities = $destination->activities ?? [];
        $nearby = $destination->hasCoordinates()
            ? $repository->nearby($destination, 3)
                ->map(fn (Destination $nearbyDestination): string => $nearbyDestination->name ?? '')
                ->filter()
                ->implode(', ')
            : '';
        $unknown = (string) config('ontology.unknown_label', 'tidak tercatat');

        $reply = sprintf(
            "**%s**\n\n%s\n\n- Kategori: %s\n- Wilayah: %s\n- Rating: %s\n- Harga tiket: %s\n- Alamat: %s\n- Jadwal: %s\n- Fasilitas: %s\n- Aktivitas: %s\n- Destinasi terdekat: %s",
            $destination->name ?? 'Destinasi',
            ($destination->fullDescription ?? '') !== ''
                ? $destination->fullDescription
                : (($destination->shortDescription ?? '') !== '' ? $destination->shortDescription : $unknown),
            $types !== [] ? implode(', ', $types) : $unknown,
            ($destination->location ?? '') !== '' ? $destination->location : $destination->province(),
            $destination->ratingLabel(),
            $prices !== [] ? implode(', ', $prices) : $unknown,
            ($destination->address ?? '') !== '' ? $destination->address : $unknown,
            $destination->openingHoursLabel(),
            $facilities !== [] ? implode(', ', $facilities) : $unknown,
            $activities !== [] ? implode(', ', $activities) : $unknown,
            $nearby !== '' ? $nearby : $unknown,
        );

        if ($destination->hasCoordinates()) {
            $reply .= "\n\n[Lihat lokasi di Google Maps](".$destination->mapsUrl().')';
        }

        return $this->responsePayload($reply, collect([$destination]));
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function fallbackSearchReply(string $message, DestinationRepository $repository): array
    {
        $normalized = Str::lower($message);
        $limit = $this->extractRequestedLimit($normalized);
        $matches = $this->matchingDestinations($normalized, $repository->all(), $repository);
        $region = $this->extractRegion($normalized, $repository);
        $types = $this->extractTypes($normalized);

        if ($matches->isEmpty() && ($region !== null || $types !== [])) {
            $matches = $repository->search([
                'jenis' => $types[0] ?? null,
                'kabupaten' => $region,
                'sort' => 'rating',
            ])->take($limit);
        }

        if ($matches->isEmpty()) {
            return $this->responsePayload(
                'WayFinder AI sedang tidak dapat menjawab pertanyaan naratif ini. Saya belum menemukan kecocokan yang cukup di dataset lokal. Coba tanyakan nama destinasi, kategori, wilayah, atau harga tiket.',
            );
        }

        $matches = $matches->take($limit)->values();

        return $this->responsePayload(
            "WayFinder AI sedang tidak tersedia. Berikut informasi yang dapat saya temukan pada dataset lokal:\n\n"
                .$this->recommendationList($matches),
            $matches,
            $limit,
        );
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     * @return list<array{id: string, name: string, latitude: float, longitude: float, location: string}>
     */
    private function mapDestinations(Collection $destinations, int $limit = 5): array
    {
        return $destinations
            ->filter(fn (mixed $destination): bool => $destination instanceof Destination
                && $this->hasValidCoordinates($destination))
            ->map(fn (Destination $destination): array => [
                'id' => $destination->id ?? '',
                'name' => $destination->name ?? '',
                'latitude' => (float) ($destination->latitude ?? 0.0),
                'longitude' => (float) ($destination->longitude ?? 0.0),
                'location' => $destination->location ?? '',
            ])
            ->take(min(10, max(1, $limit)))
            ->values()
            ->all();
    }

    private function hasValidCoordinates(Destination $destination): bool
    {
        $latitude = $destination->latitude ?? null;
        $longitude = $destination->longitude ?? null;

        return is_numeric($latitude)
            && is_numeric($longitude)
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180;
    }

    private function responsePayload(string $reply, ?Collection $destinations = null, int $limit = 5): array
    {
        return [
            'reply' => $reply,
            'destinations' => $this->mapDestinations($destinations ?? collect(), $limit),
        ];
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     */
    private function recommendationList(Collection $destinations): string
    {
        return $destinations
            ->map(fn (Destination $destination): string => sprintf(
                '- **%s** (%s) | Rating: %s | Tiket: %s',
                $destination->name ?? 'Destinasi',
                ($destination->location ?? '') !== '' ? $destination->location : $destination->province(),
                $destination->ratingLabel(),
                $destination->priceLabel(),
            ))
            ->implode("\n");
    }

    /**
     * Normalisasi bahasa gaul, singkatan chat, dan koreksi typo ringan pada pesan pengguna.
     *
     * @return array{clean: string, corrected: string, isRecommendationIntent: bool}
     */
    private function normalizeUserQuery(string $rawMessage, ?DestinationRepository $repository = null): array
    {
        $lower = mb_strtolower(trim($rawMessage), 'UTF-8');
        $clean = trim(preg_replace('/\s+/u', ' ', $lower) ?? $lower);

        if ($clean === '') {
            return [
                'clean' => '',
                'corrected' => '',
                'isRecommendationIntent' => false,
            ];
        }

        $isRecommendationIntent = $this->hasWordOrPhrase($clean, self::RECOMMENDATION_INTENT_HINTS);

        $normalized = $clean;
        foreach (self::SLANG_PHRASE_MAP as $phrase => $replacement) {
            $normalized = preg_replace('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/iu', $replacement, $normalized) ?? $normalized;
        }

        foreach (self::SLANG_WORD_MAP as $slang => $replacement) {
            $normalized = preg_replace('/(?<!\p{L})'.preg_quote($slang, '/').'(?!\p{L})/iu', $replacement, $normalized) ?? $normalized;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
        $corrected = $this->correctQueryTypos($normalized, $repository);

        return [
            'clean' => $clean,
            'corrected' => $corrected,
            'isRecommendationIntent' => $isRecommendationIntent,
        ];
    }

    private function correctQueryTypos(string $text, ?DestinationRepository $repository): string
    {
        if ($repository === null) {
            return $text;
        }

        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens) || $tokens === []) {
            return $text;
        }

        $vocabularyWords = $this->buildOntologyTokenVocabulary($repository);
        if ($vocabularyWords === []) {
            return $text;
        }

        $knownTokens = array_merge(
            self::STOPWORDS,
            self::FREE_HINTS,
            self::CHEAP_HINTS,
            self::TOP_HINTS,
            self::COMPLEX_HINTS,
            self::RECOMMENDATION_INTENT_HINTS,
            array_keys(self::TYPE_HINTS),
            array_values(self::SLANG_WORD_MAP),
            [
                'spot', 'foto', 'mana', 'saja', 'informasi', 'kabupaten', 'kota', 'sumatera', 'utara',
                'total', 'jumlah', 'statistik', 'berapa', 'semua', 'seluruh', 'tercatat', 'murah',
                'gratis', 'terdekat', 'dekat', 'buka', 'tutup', 'jam', 'harga', 'tiket', 'alamat',
            ],
        );

        foreach ($repository->filterOptions() as $labels) {
            foreach ($labels as $label) {
                $labelWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $label, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($labelWords as $labelWord) {
                    $knownTokens[] = $labelWord;
                }
            }
        }
        $knownTokens = array_values(array_unique($knownTokens));

        $correctedTokens = $tokens;
        $vocabEntries = [];
        foreach ($vocabularyWords as $word => $freq) {
            $vocabEntries[] = ['token' => (string) $word, 'frequency' => $freq];
        }

        $typoCorrector = null;
        if (class_exists(TypoCorrector::class)) {
            $typoCorrector = app()->bound(TypoCorrector::class)
                ? app(TypoCorrector::class)
                : new TypoCorrector;
        }

        foreach ($tokens as $index => $token) {
            $stripped = trim($token, ".,!?;:\"'()[]{}");
            if ($stripped === '' || mb_strlen($stripped, 'UTF-8') < 4 || ctype_digit($stripped)) {
                continue;
            }

            if (in_array($stripped, $knownTokens, true) || isset($vocabularyWords[$stripped])) {
                continue;
            }

            $replacement = null;
            if ($typoCorrector instanceof TypoCorrector) {
                $result = $typoCorrector->correct([$stripped], $vocabEntries);
                $candidate = $result['tokens'][0] ?? $stripped;
                if ($candidate !== $stripped && isset($vocabularyWords[$candidate])) {
                    $replacement = $candidate;
                }
            }

            if ($replacement === null) {
                $replacement = $this->fuzzyMatchToken($stripped, array_keys($vocabularyWords));
            }

            if ($replacement !== null && $replacement !== $stripped) {
                $correctedTokens[$index] = str_replace($stripped, $replacement, $token);
            }
        }

        return implode(' ', $correctedTokens);
    }

    /**
     * @return array<string, int>
     */
    private function buildOntologyTokenVocabulary(DestinationRepository $repository): array
    {
        $vocabulary = [];

        foreach (array_keys($repository->facets()['kabupaten'] ?? []) as $region) {
            $words = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($region, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4 && ! in_array($word, ['kabupaten', 'kota'], true)) {
                    $vocabulary[$word] = ($vocabulary[$word] ?? 0) + 5;
                }
            }
        }

        foreach ($repository->all() as $destination) {
            $nameText = trim(($destination->name ?? '').' '.($destination->alternativeName ?? ''));
            $words = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($nameText, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4) {
                    $vocabulary[$word] = ($vocabulary[$word] ?? 0) + 2;
                }
            }
        }

        return $vocabulary;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function fuzzyMatchToken(string $token, array $candidates): ?string
    {
        if (strlen($token) > 50 || $token === '') {
            return null;
        }

        $bestCandidate = null;
        $bestScore = 0.0;
        $tokenCompact = str_replace('-', '', $token);
        $maxDistance = strlen($tokenCompact) <= 5 ? 1 : 2;

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 50) {
                continue;
            }

            $candidateCompact = str_replace('-', '', $candidate);
            if ($tokenCompact === $candidateCompact) {
                return $candidate;
            }

            $distance = levenshtein($tokenCompact, $candidateCompact);
            similar_text($tokenCompact, $candidateCompact, $similarity);

            if (($distance <= $maxDistance && $similarity >= 75.0) || $similarity >= 82.0) {
                $score = $similarity - ($distance * 5.0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = $candidate;
                }
            }
        }

        return $bestCandidate;
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{region: ?string, types: list<string>}
     */
    private function extractHistoryContext(array $history, DestinationRepository $repository): array
    {
        $recentUserMessages = collect(array_slice($history, -8))
            ->filter(fn (array $item): bool => ($item['role'] ?? '') === 'user' && is_string($item['text'] ?? null))
            ->reverse()
            ->take(4);

        $region = null;
        $types = [];

        foreach ($recentUserMessages as $item) {
            $prev = $this->normalizeUserQuery((string) $item['text'], $repository)['corrected'];

            if ($region === null) {
                $region = $this->extractRegion($prev, $repository);
            }

            if ($types === []) {
                $types = $this->extractTypes($prev);
            }

            if ($region !== null && $types !== []) {
                break;
            }
        }

        return [
            'region' => $region,
            'types' => $types,
        ];
    }

    /**
     * @param  list<string>  $phrases
     */
    private function hasWordOrPhrase(string $message, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/iu', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function extractRegion(string $message, DestinationRepository $repository): ?string
    {
        $regions = array_keys($repository->facets()['kabupaten'] ?? []);
        usort($regions, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        foreach ($regions as $region) {
            if ($region !== '' && str_contains($message, Str::lower($region))) {
                return $region;
            }
        }

        foreach ($regions as $region) {
            $short = trim((string) preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $region));
            if ($short !== '' && mb_strlen($short, 'UTF-8') >= 4
                && preg_match('/(?<!\p{L})'.preg_quote(Str::lower($short), '/').'(?!\p{L})/iu', $message) === 1) {
                return $region;
            }
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (strlen($token) < 4 || strlen($token) > 50) {
                continue;
            }

            foreach ($regions as $region) {
                $short = Str::lower(trim((string) preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $region)));
                if ($short === '' || strlen($short) > 50) {
                    continue;
                }

                $maxDistance = strlen($token) <= 5 ? 1 : 2;
                $distance = levenshtein($token, $short);
                similar_text($token, $short, $similarity);
                if (($distance <= $maxDistance && $similarity >= 75.0) || $similarity >= 82.0) {
                    return $region;
                }
            }
        }

        return null;
    }

    private function extractRequestedLimit(string $lower, int $default = 5, int $max = 10): int
    {
        $max = max(1, $max);
        $default = min($max, max(0, $default));
        $numberWords = [
            'satu' => 1,
            'dua' => 2,
            'tiga' => 3,
            'empat' => 4,
            'lima' => 5,
            'enam' => 6,
            'tujuh' => 7,
            'delapan' => 8,
            'sembilan' => 9,
            'sepuluh' => 10,
        ];
        $numberPattern = '(?:10|[1-9]|'.implode('|', array_keys($numberWords)).')';
        $pattern = '/(?<![\p{L}\p{N}.,])(?<number>'.$numberPattern.')(?![\p{L}\p{N}]|[.,]\d)/iu';
        preg_match_all($pattern, $lower, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['number'] ?? [] as [$number, $offset]) {
            $number = Str::lower($number);
            $after = substr($lower, $offset + strlen($number));
            $before = substr($lower, max(0, $offset - 24), min(24, $offset));
            $context = substr($lower, max(0, $offset - 32), strlen($number) + 64);

            if (preg_match('/^\s*(?:rb|ribu|k|jam|hari|malam|bintang|rating|km)\b/iu', $after) === 1
                || preg_match('/\b(?:rp|harga|rating|bintang)\s*$/iu', $before) === 1
                || preg_match('/\b(?:wisata|destinasi|rekomendasi|rekomen|saran|list|daftar|lagi|pilihan|tempat)\b/iu', $context) !== 1) {
                continue;
            }

            $value = ctype_digit($number) ? (int) $number : ($numberWords[$number] ?? 0);

            return min($max, max(1, $value));
        }

        return $default;
    }

    private function isComplexNarrativeQuery(string $lower): bool
    {
        return $this->hasAny($lower, self::COMPLEX_HINTS);
    }

    /**
     * @return list<string>
     */
    private function extractAttributeTerms(string $message, DestinationRepository $repository): array
    {
        $terms = [];

        foreach (self::ATTRIBUTE_HINTS as $hint => $aliases) {
            if (str_contains($message, $hint)) {
                $terms = [...$terms, ...$aliases];
            }
        }

        foreach ($repository->filterOptions() as $labels) {
            foreach ($labels as $label) {
                $normalizedLabel = Str::lower($label);

                if ($normalizedLabel !== ''
                    && ! in_array($normalizedLabel, ['tempat wisata', 'wisata', 'destinasi'], true)
                    && str_contains($message, $normalizedLabel)) {
                    $terms[] = $normalizedLabel;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  list<string>  $terms
     */
    private function matchesAttributeTerms(Destination $destination, array $terms): bool
    {
        $searchableAttributes = Str::lower(implode(' ', [
            ...($destination->facilities ?? []),
            ...($destination->activities ?? []),
            $destination->shortDescription ?? '',
        ]));

        foreach ($terms as $term) {
            if ($term !== '' && str_contains($searchableAttributes, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractTypes(string $message): array
    {
        $types = [];

        foreach (self::TYPE_HINTS as $hint => $type) {
            if (str_contains($message, $hint) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     * @return Collection<int, Destination>
     */
    private function findMentionedDestinations(string $message, Collection $destinations): Collection
    {
        $matches = $destinations->filter(function (Destination $destination) use ($message): bool {
            $name = Str::lower($destination->name ?? '');
            $alternative = Str::lower($destination->alternativeName ?? '');

            return ($name !== '' && str_contains($message, $name))
                || (mb_strlen($alternative) >= 4 && str_contains($message, $alternative));
        });

        if ($matches->isNotEmpty()) {
            return $matches->sortBy(fn (Destination $destination): int => -mb_strlen($destination->name ?? ''))->values();
        }

        $query = preg_replace(
            '/\b(info|informasi|tiket|harga|alamat|jadwal|profil|jelaskan|tentang|destinasi|wisata|di|ke|dong|tolong|please)\b/u',
            ' ',
            $message,
        ) ?? $message;
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        if (mb_strlen($query) < 5) {
            return collect();
        }

        $candidates = $destinations->map(function (Destination $destination) use ($query): array {
            $name = Str::lower($destination->name ?? '');
            similar_text($query, $name, $similarity);

            return ['destination' => $destination, 'similarity' => $similarity];
        })->filter(fn (array $candidate): bool => $candidate['similarity'] >= 72.0);

        $best = $candidates->sortByDesc('similarity')->first();

        return $best !== null ? collect([$best['destination']]) : collect();
    }

    private function isGreeting(string $message): bool
    {
        $normalized = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $message) ?? $message);

        return in_array($normalized, [
            'halo', 'hai', 'hi', 'hello', 'selamat pagi', 'selamat siang', 'selamat sore',
            'selamat malam', 'apa kabar',
        ], true);
    }

    /**
     * @param  list<string>  $hints
     */
    private function hasAny(string $message, array $hints): bool
    {
        foreach ($hints as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function isFree(Destination $destination): bool
    {
        foreach ($destination->prices ?? [] as $price) {
            if (in_array(Str::lower(trim((string) $price)), self::FREE_HINTS, true)) {
                return true;
            }
        }

        return ($destination->lowestPrice() ?? null) === 0.0;
    }

    private function endpoint(): string
    {
        $endpoint = (string) config('services.gemini.endpoint');
        $model = (string) config('services.gemini.model');

        return $endpoint.'/models/'.$model.':generateContent';
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return list<array{role: string, parts: list<array{text: string}>}>
     */
    private function contents(string $message, array $history): array
    {
        $contents = collect(array_slice($history, -6))
            ->map(fn (array $item): array => [
                'role' => $item['role'],
                'parts' => [['text' => $item['text']]],
            ])
            ->values()
            ->all();

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $message]],
        ];

        return $contents;
    }

    private function systemInstruction(string $message, DestinationRepository $repository): string
    {
        return <<<PROMPT
Anda adalah WayFinder AI, pemandu wisata digital untuk destinasi di Sumatera Utara.
Anda memahami bahasa gaul Indonesia, singkatan chat sehari-hari, maupun kesalahan ketik pengguna dengan ramah dan santai, tanpa kaku.
Selalu perhatikan konteks pesan-pesan sebelumnya pada riwayat percakapan (contents) ketika pengguna mengajukan pertanyaan lanjutan seperti "yang dekat situ apa?", "bandingkan keduanya", atau "buatkan itinerary-nya".
Jawab dengan ramah, ringkas, dan informatif. Jangan membuka jawaban dengan sapaan pembuka.
Gunakan hanya data dari konteks ontologi di bawah. Jika data tidak tersedia, katakan terus terang
bahwa informasinya belum tercatat dan jangan mengarang angka, alamat, atau nomor kontak.
Jawab dalam bahasa yang digunakan pengguna. Jangan menampilkan data pribadi atau kredensial.

KONTEKS ONTOLOGI (ringkas):
<ontology_context>
{$this->context($message, $repository)}
</ontology_context>
PROMPT;
    }

    private function context(string $message, DestinationRepository $repository): string
    {
        $destinations = $repository->all();
        $facets = $repository->facets();
        $lower = Str::lower($message);
        $matched = $this->matchingDestinations($lower, $destinations, $repository);

        $lines = [
            'Total destinasi: '.$destinations->count(),
            'Jumlah per kategori: '.collect($facets['types'] ?? [])
                ->map(fn (int $count, string $type): string => $type.'='.$count)
                ->implode(', '),
            'Kabupaten/kota tercatat: '.count($facets['kabupaten'] ?? []),
            'Rata-rata rating: '.($facets['average_rating'] ?? 'tidak tersedia')
                .' dari '.($facets['rated_count'] ?? 0).' destinasi berating.',
            '',
            'Destinasi dengan rating tertinggi:',
        ];

        foreach ($repository->topRated(5) as $destination) {
            $lines[] = $this->describe($destination);
        }

        if ($matched->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Destinasi yang relevan dengan pertanyaan pengguna:';

            foreach ($matched as $destination) {
                $lines[] = $this->describe($destination);
            }
        }

        return Str::limit(implode("\n", $lines), 6000);
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     * @return Collection<int, Destination>
     */
    private function matchingDestinations(string $lower, Collection $destinations, DestinationRepository $repository): Collection
    {
        $matched = $destinations->filter(function (Destination $destination) use ($lower): bool {
            $name = Str::lower($destination->name ?? '');
            $alternative = Str::lower($destination->alternativeName ?? '');

            return ($name !== '' && str_contains($lower, $name))
                || (mb_strlen($alternative) >= 4 && str_contains($lower, $alternative));
        });

        if ($matched->count() < 8) {
            $words = collect(preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->filter(fn (string $word): bool => mb_strlen($word) >= 4)
                ->reject(fn (string $word): bool => in_array($word, self::STOPWORDS, true))
                ->take(4);

            foreach ($words as $word) {
                $matched = $matched->merge($repository->search(['q' => $word, 'sort' => 'rating'])->take(3));
            }
        }

        foreach (self::TYPE_HINTS as $hint => $type) {
            if (str_contains($lower, $hint)) {
                $matched = $matched->merge($repository->search(['jenis' => $type, 'sort' => 'rating'])->take(2));
            }
        }

        $attributeTerms = $this->extractAttributeTerms($lower, $repository);
        if ($attributeTerms !== []) {
            $matched = $matched->merge(
                $destinations
                    ->filter(fn (Destination $destination): bool => $this->matchesAttributeTerms($destination, $attributeTerms))
                    ->take(5),
            );
        }

        return $matched->unique('id')->take(10)->values();
    }

    private function describe(Destination $destination): string
    {
        $facilities = ($destination->facilities ?? []) !== []
            ? implode(', ', $destination->facilities ?? [])
            : 'tidak tercatat';
        $activities = ($destination->activities ?? []) !== []
            ? implode(', ', $destination->activities ?? [])
            : 'tidak tercatat';

        return sprintf(
            '- %s | kategori: %s | lokasi: %s | rating: %s | tiket: %s | fasilitas: %s | aktivitas: %s | %s',
            $destination->name ?? 'Destinasi',
            ($destination->types ?? []) === [] ? 'tidak tercatat' : implode(', ', $destination->types ?? []),
            ($destination->location ?? '') !== '' ? $destination->location : $destination->province(),
            $destination->ratingLabel(),
            $destination->priceLabel(),
            $facilities,
            $activities,
            Str::limit(
                ($destination->shortDescription ?? '') !== '' ? $destination->shortDescription : 'deskripsi belum tercatat',
                180,
            ),
        );
    }
}
