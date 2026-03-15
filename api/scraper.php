<?php
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';

/**
 * DataScraper v3 — Comprehensive region map, all prefixes, @gmail.com email, force-refresh
 */
class DataScraper {
    private PDO $db;
    private string $apiKey;
    private ?EnhancedRateLimiter $rateLimiter = null;

    // SORTED BY LENGTH DESCENDING — critical for greedy prefix match
    const INST_PREFIXES = [
        'satpolpp','disdukcapil','diskominfo','disbudpar','distamben',
        'dprkkab','dprkab','dprk','bkpsdm',
        'pemadam','beacukai','pemkot','pemkab','polres','polsek',
        'dishub','dinkes','dispora','dinas',
        'damkar','bpbd','dprd','rsud','kpud',
        'bnnp','bnnk','afiain','afiai','knpid','knpi',
        'satpol','polpp','kantor','bawaslu','kejaksaan','pengadilan','kpu',
        'puskesmas','perbasi','disnak','disnaker','bapenda',
        'bapperida','bappeda','bappelitbang',
    ];

    const REGION_MAP = [
        // ── ACEH (lengkap 23 kabupaten/kota) ─────────────────
        'bandaaceh'         => 'Banda Aceh',
        'sabang'            => 'Sabang',
        'langsa'            => 'Langsa',
        'lhokseumawe'       => 'Lhokseumawe',
        'subulussalam'      => 'Subulussalam',
        'acehbesar'         => 'Aceh Besar',
        'acehbarat'         => 'Aceh Barat',
        'acehbaratdaya'     => 'Aceh Barat Daya',
        'acehjaya'          => 'Aceh Jaya',
        'acehselatan'       => 'Aceh Selatan',
        'acehsingkil'       => 'Aceh Singkil',
        'acehtamiang'       => 'Aceh Tamiang',
        'acehtengah'        => 'Aceh Tengah',
        'acehtenggara'      => 'Aceh Tenggara',
        'acehtimur'         => 'Aceh Timur',
        'acehutara'         => 'Aceh Utara',
        'benermeriah'       => 'Bener Meriah',
        'bireuen'           => 'Bireuen',
        'gayolues'          => 'Gayo Lues',
        'naganraya'         => 'Nagan Raya',
        'pidie'             => 'Pidie',
        'pidiejaya'         => 'Pidie Jaya',
        'simeulue'          => 'Simeulue',
        // ── SUMATRA UTARA (lengkap) ───────────────────────────
        'medan'             => 'Medan',
        'binjai'            => 'Binjai',
        'tebingtinggi'      => 'Tebing Tinggi',
        'pematangsiantar'   => 'Pematang Siantar',
        'tanjungbalai'      => 'Tanjung Balai',
        'padangsidimpuan'   => 'Padang Sidimpuan',
        'sibolga'           => 'Sibolga',
        'gunungsitoli'      => 'Gunungsitoli',
        'deliserdang'       => 'Deli Serdang',
        'langkat'           => 'Langkat',
        'karo'              => 'Karo',
        'simalungun'        => 'Simalungun',
        'asahan'            => 'Asahan',
        'labuhanbatu'       => 'Labuhan Batu',
        'labuhanbatutara'   => 'Labuhan Batu Utara',
        'labuhanbatupelatan'=> 'Labuhan Batu Selatan',
        'mandailingnatal'   => 'Mandailing Natal',
        'tapanulitengah'    => 'Tapanuli Tengah',
        'tapanuliselatan'   => 'Tapanuli Selatan',
        'tapanuliutara'     => 'Tapanuli Utara',
        'tobasa'            => 'Toba',
        'samosir'           => 'Samosir',
        'pakpakbharat'      => 'Pakpak Bharat',
        'humbahasas'        => 'Humbang Hasundutan',
        'humbanghudutan'    => 'Humbang Hasundutan',
        'nias'              => 'Nias',
        'niasselatan'       => 'Nias Selatan',
        'niasutara'         => 'Nias Utara',
        'niasbarat'         => 'Nias Barat',
        'batubara'          => 'Batu Bara',
        'padanglawas'       => 'Padang Lawas',
        'padanglawasutara'  => 'Padang Lawas Utara',
        'dairi'             => 'Dairi',
        // ── SUMATRA BARAT ─────────────────────────────────────
        'padang'            => 'Padang',
        'bukittinggi'       => 'Bukittinggi',
        'padangpanjang'     => 'Padang Panjang',
        'pariaman'          => 'Pariaman',
        'payakumbuh'        => 'Payakumbuh',
        'sawahlunto'        => 'Sawahlunto',
        'solok'             => 'Solok',
        'padangpariaman'    => 'Padang Pariaman',
        'agam'              => 'Agam',
        'pasamanbarat'      => 'Pasaman Barat',
        'pasaman'           => 'Pasaman',
        'limapuluhkota'     => 'Lima Puluh Kota',
        'sijunjung'         => 'Sijunjung',
        'tanahdatar'        => 'Tanah Datar',
        'dharmasraya'       => 'Dharmasraya',
        'solokselatan'      => 'Solok Selatan',
        'pesisirselatan'    => 'Pesisir Selatan',
        'kepulauanmentawai' => 'Kepulauan Mentawai',
        // ── RIAU ─────────────────────────────────────────────
        'pekanbaru'         => 'Pekanbaru',
        'dumai'             => 'Dumai',
        'siak'              => 'Siak',
        'kampar'            => 'Kampar',
        'bengkalis'         => 'Bengkalis',
        'rohul'             => 'Rokan Hulu',
        'rokanhulu'         => 'Rokan Hulu',
        'rohil'             => 'Rokan Hilir',
        'rokanhilir'        => 'Rokan Hilir',
        'pelalawan'         => 'Pelalawan',
        'inhil'             => 'Indragiri Hilir',
        'indragirilir'      => 'Indragiri Hilir',
        'inhu'              => 'Indragiri Hulu',
        'indragirilur'      => 'Indragiri Hulu',
        'kepulauanmeranti'  => 'Kepulauan Meranti',
        'kuansinggi'        => 'Kuantan Singingi',
        // ── KEPRI ─────────────────────────────────────────────
        'batam'             => 'Batam',
        'tanjungpinang'     => 'Tanjung Pinang',
        'bintan'            => 'Bintan',
        'lingga'            => 'Lingga',
        'karimun'           => 'Karimun',
        'natuna'            => 'Natuna',
        'kepulauananambas'  => 'Kepulauan Anambas',
        // ── JAMBI ─────────────────────────────────────────────
        'jambi'             => 'Jambi',
        'batanghari'        => 'Batang Hari',
        'muarojambi'        => 'Muaro Jambi',
        'sarolangun'        => 'Sarolangun',
        'merangin'          => 'Merangin',
        'bungo'             => 'Bungo',
        'tebo'              => 'Tebo',
        'tanjungjabungbarat'=> 'Tanjung Jabung Barat',
        'tanjungjabungtimur'=> 'Tanjung Jabung Timur',
        'sungaipenuh'       => 'Sungai Penuh',
        'kerinci'           => 'Kerinci',
        // ── SUMATRA SELATAN ────────────────────────────────────
        'palembang'         => 'Palembang',
        'prabumulih'        => 'Prabumulih',
        'pagarlam'          => 'Pagar Alam',
        'lubuklinggau'      => 'Lubuk Linggau',
        'ogankomeringilir'  => 'Ogan Komering Ilir',
        'oganilir'          => 'Ogan Ilir',
        'ogankomeringulutimur' => 'Ogan Komering Ulu Timur',
        'ogankomeringuluselatan' => 'Ogan Komering Ulu Selatan',
        'ogankomeringulu'   => 'Ogan Komering Ulu',
        'muaraenim'         => 'Muara Enim',
        'lahat'             => 'Lahat',
        'empatlawang'       => 'Empat Lawang',
        'musirawasutara'    => 'Musi Rawas Utara',
        'musirawas'         => 'Musi Rawas',
        'musibanyuasin'     => 'Musi Banyuasin',
        'banyuasin'         => 'Banyuasin',
        'penukalabablematangilir' => 'Penukal Abab Lematang Ilir',
        // ── BENGKULU ──────────────────────────────────────────
        'bengkulu'          => 'Bengkulu',
        'bengkulutengah'    => 'Bengkulu Tengah',
        'bengkuluutara'     => 'Bengkulu Utara',
        'bengkuluselatan'   => 'Bengkulu Selatan',
        'mukomuko'          => 'Mukomuko',
        'rejanglebong'      => 'Rejang Lebong',
        'lebong'            => 'Lebong',
        'kepahiang'         => 'Kepahiang',
        'kaur'              => 'Kaur',
        'seluma'            => 'Seluma',
        // ── LAMPUNG ───────────────────────────────────────────
        'bandarlampung'     => 'Bandar Lampung',
        'metro'             => 'Metro',
        'lampungselatan'    => 'Lampung Selatan',
        'lampungtengah'     => 'Lampung Tengah',
        'lampungtimur'      => 'Lampung Timur',
        'lampungutara'      => 'Lampung Utara',
        'lampungbarat'      => 'Lampung Barat',
        'pesawaran'         => 'Pesawaran',
        'pringsewu'         => 'Pringsewu',
        'tanggamus'         => 'Tanggamus',
        'pesisirbarat'      => 'Pesisir Barat',
        'waykanan'          => 'Way Kanan',
        'mesuji'            => 'Mesuji',
        'tulangbawangbarat' => 'Tulang Bawang Barat',
        'tulangbawang'      => 'Tulang Bawang',
        // ── BANGKA BELITUNG ───────────────────────────────────
        'pangkalpinang'     => 'Pangkal Pinang',
        'bangka'            => 'Bangka',
        'bangkatengah'      => 'Bangka Tengah',
        'bangkaselatan'     => 'Bangka Selatan',
        'bangkabarat'       => 'Bangka Barat',
        'belitung'          => 'Belitung',
        'belitungtimur'     => 'Belitung Timur',
        // ── BANTEN ────────────────────────────────────────────
        'serang'            => 'Serang',
        'cilegon'           => 'Cilegon',
        'tangerang'         => 'Tangerang',
        'tangerangselatan'  => 'Tangerang Selatan',
        'lebak'             => 'Lebak',
        'pandeglang'        => 'Pandeglang',
        'kanwilbanten'      => 'Kanwil Banten',
        // ── JAWA BARAT ────────────────────────────────────────
        'jakarta'           => 'Jakarta',
            // ── Jakarta Kecamatan ──────────────────────────────
            'setiabudi'         => 'Setiabudi',
            'tebet'             => 'Tebet',
            'pancoran'          => 'Pancoran',
            'kebayoranbaru'     => 'Kebayoran Baru',
            'kebayoranlama'     => 'Kebayoran Lama',
            'cilandak'          => 'Cilandak',
            'pasarminggu'       => 'Pasar Minggu',
            'jagakarsa'         => 'Jagakarsa',
            'mampang'           => 'Mampang',
            'pulogadung'        => 'Pulogadung',
            'cakung'            => 'Cakung',
            'durensawit'        => 'Duren Sawit',
            'kramatjati'        => 'Kramat Jati',
            'jatinegara'        => 'Jatinegara',
            'penjaringan'       => 'Penjaringan',
            'pademangan'        => 'Pademangan',
            'tanjungpriok'      => 'Tanjung Priok',
            'kelapagading'      => 'Kelapa Gading',
            'cempakaputih'      => 'Cempaka Putih',
            'kemayoran'         => 'Kemayoran',
            'sawahbesar'        => 'Sawah Besar',
            'tanahabang'        => 'Tanah Abang',
            'gambir'            => 'Gambir',
            'menteng'           => 'Menteng',
            'kalideres'         => 'Kalideres',
            'cengkareng'        => 'Cengkareng',
            'grogol'            => 'Grogol',
            'tambora'           => 'Tambora',
            'palmerah'          => 'Palmerah',
            'kebonjeruk'        => 'Kebon Jeruk',
            // ── Tangerang Kecamatan ────────────────────────────
            'gadingserpong'     => 'Gading Serpong',
            'serpong'           => 'Serpong',
            'pamulang'          => 'Pamulang',
            'ciputat'           => 'Ciputat',
            'pondokaren'        => 'Pondok Aren',
            'bintaro'           => 'Bintaro',
            'larangan'          => 'Larangan',
            'ciledug'           => 'Ciledug',
            // ── Bekasi Kecamatan ───────────────────────────────
            'jatiasih'          => 'Jatiasih',
            'pondokgede'        => 'Pondok Gede',
            'bekasibarat'       => 'Bekasi Barat',
            'bekasitimur'       => 'Bekasi Timur',
        'jakartapusat'      => 'Jakarta Pusat',
        'jakartautara'      => 'Jakarta Utara',
        'jakartabarat'      => 'Jakarta Barat',
        'jakartaselatan'    => 'Jakarta Selatan',
        'jakartatimur'      => 'Jakarta Timur',
        'bogor'             => 'Bogor',
        'depok'             => 'Depok',
        'bekasi'            => 'Bekasi',
        'karawang'          => 'Karawang',
        'purwakarta'        => 'Purwakarta',
        'subang'            => 'Subang',
        'cianjur'           => 'Cianjur',
        'sukabumi'          => 'Sukabumi',
        'bandung'           => 'Bandung',
        'cimahi'            => 'Cimahi',
        'bandungbarat'      => 'Bandung Barat',
        'garut'             => 'Garut',
        'tasikmalaya'       => 'Tasikmalaya',
        'ciamis'            => 'Ciamis',
        'pangandaran'       => 'Pangandaran',
        'kuningan'          => 'Kuningan',
        'cirebon'           => 'Cirebon',
        'indramayu'         => 'Indramayu',
        'majalengka'        => 'Majalengka',
        'sumedang'          => 'Sumedang',
        'marunda'           => 'Marunda',
        // ── JAWA TENGAH ───────────────────────────────────────
        'semarang'          => 'Semarang',
        'solo'              => 'Solo',
        'surakarta'         => 'Surakarta',
        'magelang'          => 'Magelang',
        'salatiga'          => 'Salatiga',
        'pekalongan'        => 'Pekalongan',
        'tegal'             => 'Tegal',
        'klaten'            => 'Klaten',
        'boyolali'          => 'Boyolali',
        'sukoharjo'         => 'Sukoharjo',
        'wonogiri'          => 'Wonogiri',
        'karanganyar'       => 'Karanganyar',
        'sragen'            => 'Sragen',
        'grobogan'          => 'Grobogan',
        'blora'             => 'Blora',
        'rembang'           => 'Rembang',
        'pati'              => 'Pati',
        'kudus'             => 'Kudus',
        'jepara'            => 'Jepara',
        'demak'             => 'Demak',
        'kendal'            => 'Kendal',
        'batang'            => 'Batang',
        'pemalang'          => 'Pemalang',
        'brebes'            => 'Brebes',
        'banjarnegara'      => 'Banjarnegara',
        'banyumas'          => 'Banyumas',
        'cilacap'           => 'Cilacap',
        'kebumen'           => 'Kebumen',
        'purworejo'         => 'Purworejo',
        'wonosobo'          => 'Wonosobo',
        'temanggung'        => 'Temanggung',
        'purbalingga'       => 'Purbalingga',
        'banjarnegara'      => 'Banjarnegara',
        // ── DI YOGYAKARTA ─────────────────────────────────────
        'yogyakarta'        => 'Yogyakarta',
        'sleman'            => 'Sleman',
        'bantul'            => 'Bantul',
        'gunungkidul'       => 'Gunung Kidul',
        'kulonprogo'        => 'Kulon Progo',
        // ── JAWA TIMUR ────────────────────────────────────────
        'surabaya'          => 'Surabaya',
        'pakuwon'           => 'Pakuwon',
        'darmo'             => 'Darmo',
        'wonokromo'         => 'Wonokromo',
        'gubeng'            => 'Gubeng',
        'tegalsari'         => 'Tegalsari',
        'genteng'           => 'Genteng',
        'bubutan'           => 'Bubutan',
        'tambaksari'        => 'Tambaksari',
        'mulyorejo'         => 'Mulyorejo',
        'sukolilo'          => 'Sukolilo',
        'rungkut'           => 'Rungkut',
        'wonocolo'          => 'Wonocolo',
        'wiyung'            => 'Wiyung',
        'sawahan'           => 'Sawahan',
        'sukomanunggal'     => 'Sukomanunggal',
        'tandes'            => 'Tandes',
        'lakarsantri'       => 'Lakarsantri',
        'benowo'            => 'Benowo',
        'asemrowo'          => 'Asemrowo',
        'krembangan'        => 'Krembangan',
        'kenjeran'          => 'Kenjeran',
        'malang'            => 'Malang',
        'sidoarjo'          => 'Sidoarjo',
        'mojokerto'         => 'Mojokerto',
        'jombang'           => 'Jombang',
        'kediri'            => 'Kediri',
        'blitar'            => 'Blitar',
        'tulungagung'       => 'Tulungagung',
        'trenggalek'        => 'Trenggalek',
        'nganjuk'           => 'Nganjuk',
        'madiun'            => 'Madiun',
        'ngawi'             => 'Ngawi',
        'magetan'           => 'Magetan',
        'ponorogo'          => 'Ponorogo',
        'pacitan'           => 'Pacitan',
        'pasuruan'          => 'Pasuruan',
        'probolinggo'       => 'Probolinggo',
        'lumajang'          => 'Lumajang',
        'jember'            => 'Jember',
        'bondowoso'         => 'Bondowoso',
        'situbondo'         => 'Situbondo',
        'banyuwangi'        => 'Banyuwangi',
        'gresik'            => 'Gresik',
        'lamongan'          => 'Lamongan',
        'tuban'             => 'Tuban',
        'bojonegoro'        => 'Bojonegoro',
        'pamekasan'         => 'Pamekasan',
        'sampang'           => 'Sampang',
        'sumenep'           => 'Sumenep',
        'bangkalan'         => 'Bangkalan',
        // ── BALI ──────────────────────────────────────────────
        'denpasar'          => 'Denpasar',
        'badung'            => 'Badung',
        'gianyar'           => 'Gianyar',
        'tabanan'           => 'Tabanan',
        'bangli'            => 'Bangli',
        'klungkung'         => 'Klungkung',
        'karangasem'        => 'Karangasem',
        'buleleng'          => 'Buleleng',
        'jembrana'          => 'Jembrana',
        // ── NTB ───────────────────────────────────────────────
        'mataram'           => 'Mataram',
        'bima'              => 'Bima',
        'dompu'             => 'Dompu',
        'sumbawa'           => 'Sumbawa',
        'sumbawabare'       => 'Sumbawa Barat',
        'lotim'             => 'Lombok Timur',
        'lobar'             => 'Lombok Barat',
        'loteng'            => 'Lombok Tengah',
        'lotnor'            => 'Lombok Utara',
        'lombokbarat'       => 'Lombok Barat',
        'lomboktengah'      => 'Lombok Tengah',
        'lomboktimur'       => 'Lombok Timur',
        'lombokutara'       => 'Lombok Utara',
        'nusatenggarabarat' => 'Nusa Tenggara Barat',
        'usatenggarabarat'  => 'Nusa Tenggara Barat', // n dimakan prefix
        // ── NTT ───────────────────────────────────────────────
        'kupang'            => 'Kupang',
        'ende'              => 'Ende',
        'flores'            => 'Flores',
        'manggarai'         => 'Manggarai',
        'manggaraibarat'    => 'Manggarai Barat',
        'manggaraitimur'    => 'Manggarai Timur',
        'ngada'             => 'Ngada',
        'nagekeo'           => 'Nagekeo',
        'sumba'             => 'Sumba',
        'sumbatimur'        => 'Sumba Timur',
        'sumbabarat'        => 'Sumba Barat',
        'sumbabaratdaya'    => 'Sumba Barat Daya',
        'sumbatengah'       => 'Sumba Tengah',
        'sikaatimor'        => 'Sikka',
        'sikka'             => 'Sikka',
        'timortenguhtara'   => 'Timor Tengah Utara',
        'timortengahselatan'=> 'Timor Tengah Selatan',
        'belu'              => 'Belu',
        'alor'              => 'Alor',
        'lembata'           => 'Lembata',
        'flotim'            => 'Flores Timur',
        'florestimur'       => 'Flores Timur',
        'rote'              => 'Rote Ndao',
        'sabu'              => 'Sabu Raijua',
        'malaka'            => 'Malaka',
        'nusatenggaratimur' => 'Nusa Tenggara Timur',
        'usatenggaratimur'  => 'Nusa Tenggara Timur', // n dimakan prefix
        // ── KALIMANTAN BARAT ──────────────────────────────────
        'pontianak'         => 'Pontianak',
        'singkawang'        => 'Singkawang',
        'sambas'            => 'Sambas',
        'bengkayang'        => 'Bengkayang',
        'landak'            => 'Landak',
        'mempawah'          => 'Mempawah',
        'kuburaya'          => 'Kubu Raya',
        'sanggau'           => 'Sanggau',
        'sekadau'           => 'Sekadau',
        'sintang'           => 'Sintang',
        'melawi'            => 'Melawi',
        'ketapang'          => 'Ketapang',
        'kayongutara'       => 'Kayong Utara',
        'kapuashulu'        => 'Kapuas Hulu',
        // ── KALIMANTAN TENGAH ─────────────────────────────────
        'palangkaraya'      => 'Palangka Raya',
        'kotawaringintimur' => 'Kotawaringin Timur',
        'kotawaringinbarat' => 'Kotawaringin Barat',
        'katingan'          => 'Katingan',
        'gunungmas'         => 'Gunung Mas',
        'seruyan'           => 'Seruyan',
        'sukamara'          => 'Sukamara',
        'lamandau'          => 'Lamandau',
        'kapuas'            => 'Kapuas',
        'pulangpisau'       => 'Pulang Pisau',
        'baritotimur'       => 'Barito Timur',
        'baritoutara'       => 'Barito Utara',
        'baritokuala'       => 'Barito Kuala',
        'barito'            => 'Barito',
        // ── KALIMANTAN SELATAN ────────────────────────────────
        'banjarmasin'       => 'Banjarmasin',
        'banjarbaru'        => 'Banjarbaru',
        'banjar'            => 'Banjar',
        'tanahlaut'         => 'Tanah Laut',
        'tanahbumbu'        => 'Tanah Bumbu',
        'kotabaru'          => 'Kotabaru',
        'tabalong'          => 'Tabalong',
        'tapin'             => 'Tapin',
        'balangan'          => 'Balangan',
        'hulungaisselatan'  => 'Hulu Sungai Selatan',
        'hulungaistengah'   => 'Hulu Sungai Tengah',
        'hulungaisutara'    => 'Hulu Sungai Utara',
        'hss'               => 'Hulu Sungai Selatan',
        'hst'               => 'Hulu Sungai Tengah',
        'hsu'               => 'Hulu Sungai Utara',
        // ── KALIMANTAN TIMUR ──────────────────────────────────
        'samarinda'         => 'Samarinda',
        'balikpapan'        => 'Balikpapan',
        'bontang'           => 'Bontang',
        'kutaikartanegara'  => 'Kutai Kartanegara',
        'kutaibarat'        => 'Kutai Barat',
        'kutaitimur'        => 'Kutai Timur',
        'mahakamulu'        => 'Mahakam Ulu',
        'berau'             => 'Berau',
        'paser'             => 'Paser',
        'penajampaserutara' => 'Penajam Paser Utara',
        // ── KALIMANTAN UTARA ──────────────────────────────────
        'tarakan'           => 'Tarakan',
        'bulungan'          => 'Bulungan',
        'malinau'           => 'Malinau',
        'nunukan'           => 'Nunukan',
        'tanahtidung'       => 'Tana Tidung',
        // ── SULAWESI UTARA ────────────────────────────────────
        'manado'            => 'Manado',
        'bitung'            => 'Bitung',
        'tomohon'           => 'Tomohon',
        'kotamobagu'        => 'Kotamobagu',
        'minahasa'          => 'Minahasa',
        'minahasautara'     => 'Minahasa Utara',
        'minahasaselatan'   => 'Minahasa Selatan',
        'minahasatenggara'  => 'Minahasa Tenggara',
        'bolmong'           => 'Bolaang Mongondow',
        'bolmongutara'      => 'Bolaang Mongondow Utara',
        'bolmongtimur'      => 'Bolaang Mongondow Timur',
        'bolmongselatan'    => 'Bolaang Mongondow Selatan',
        'bolaangmongondow'  => 'Bolaang Mongondow',
        'sangihe'           => 'Kepulauan Sangihe',
        'talaud'            => 'Kepulauan Talaud',
        'sitarotimur'       => 'Sitaro',
        // ── GORONTALO ─────────────────────────────────────────
        'gorontalo'         => 'Gorontalo',
        'boalemo'           => 'Boalemo',
        'pohuwato'          => 'Pohuwato',
        'bonebolango'       => 'Bone Bolango',
        'gorontaloutara'    => 'Gorontalo Utara',
        'gorut'             => 'Gorontalo Utara',
        // ── SULAWESI TENGAH ───────────────────────────────────
        'palu'              => 'Palu',
        'donggala'          => 'Donggala',
        'sigi'              => 'Sigi',
        'poso'              => 'Poso',
        'morowali'          => 'Morowali',
        'morowalitara'      => 'Morowali Utara',
        'banggai'           => 'Banggai',
        'bangagaikepluan'   => 'Banggai Kepulauan',
        'bangagailaut'      => 'Banggai Laut',
        'tolioli'           => 'Toli-Toli',
        'buol'              => 'Buol',
        'parigi'            => 'Parigi Moutong',
        'parmo'             => 'Parigi Moutong',
        'tojo'              => 'Tojo Una-Una',
        // ── SULAWESI BARAT ────────────────────────────────────
        'mamuju'            => 'Mamuju',
        'mamujutengah'      => 'Mamuju Tengah',
        'majene'            => 'Majene',
        'polman'            => 'Polewali Mandar',
        'polewalimandar'    => 'Polewali Mandar',
        'mamasa'            => 'Mamasa',
        'pasangkayu'        => 'Pasangkayu',
        // ── SULAWESI SELATAN ──────────────────────────────────
        'makassar'          => 'Makassar',
        'parepare'          => 'Parepare',
        'palopo'            => 'Palopo',
        'gowa'              => 'Gowa',
        'takalar'           => 'Takalar',
        'jeneponto'         => 'Jeneponto',
        'bantaeng'          => 'Bantaeng',
        'bulukumba'         => 'Bulukumba',
        'sinjai'            => 'Sinjai',
        'bone'              => 'Bone',
        'soppeng'           => 'Soppeng',
        'wajo'              => 'Wajo',
        'sidrap'            => 'Sidenreng Rappang',
        'pinrang'           => 'Pinrang',
        'enrekang'          => 'Enrekang',
        'tanatoraja'        => 'Tana Toraja',
        'torajautara'       => 'Toraja Utara',
        'luwu'              => 'Luwu',
        'luwutimur'         => 'Luwu Timur',
        'luwuutara'         => 'Luwu Utara',
        'kepulauanselayar'  => 'Kepulauan Selayar',
        'maros'             => 'Maros',
        'pangkep'           => 'Pangkep',
        'barru'             => 'Barru',
        // ── SULAWESI TENGGARA ─────────────────────────────────
        'kendari'           => 'Kendari',
        'baubau'            => 'Bau-Bau',
        'kolaka'            => 'Kolaka',
        'kolakatara'        => 'Kolaka Utara',
        'kolakatimur'       => 'Kolaka Timur',
        'konawe'            => 'Konawe',
        'konaweselatan'     => 'Konawe Selatan',
        'konaweutara'       => 'Konawe Utara',
        'konaweisland'      => 'Konawe Kepulauan',
        'buton'             => 'Buton',
        'butonselatan'      => 'Buton Selatan',
        'butontenagh'       => 'Buton Tengah',
        'butontenggara'     => 'Buton Tenggara',
        'butonutara'        => 'Buton Utara',
        'muna'              => 'Muna',
        'munabarat'         => 'Muna Barat',
        'wakatobi'          => 'Wakatobi',
        'bombana'           => 'Bombana',
        // ── MALUKU ────────────────────────────────────────────
        'ambon'             => 'Ambon',
        'tual'              => 'Tual',
        'malukutengah'      => 'Maluku Tengah',
        'malukutenggara'    => 'Maluku Tenggara',
        'malukubaratdaya'   => 'Maluku Barat Daya',
        'seram'             => 'Seram Bagian Barat',
        'seramtimur'        => 'Seram Bagian Timur',
        'buru'              => 'Buru',
        'buruselatan'       => 'Buru Selatan',
        'aru'               => 'Kepulauan Aru',
        // ── MALUKU UTARA ──────────────────────────────────────
        'ternate'           => 'Ternate',
        'tidore'            => 'Tidore Kepulauan',
        'halmahera'         => 'Halmahera',
        'halmaherautara'    => 'Halmahera Utara',
        'halmaherapelatan'  => 'Halmahera Selatan',
        'halmahertengah'    => 'Halmahera Tengah',
        'halmaherbarat'     => 'Halmahera Barat',
        'halmaheratimur'    => 'Halmahera Timur',
        'kepulauansula'     => 'Kepulauan Sula',
        'morotai'           => 'Pulau Morotai',
        'taliabu'           => 'Pulau Taliabu',
        'malukuutara'       => 'Maluku Utara',
        // ── PAPUA (umum) ──────────────────────────────────────
        'jayapura'          => 'Jayapura',
        'sorong'            => 'Sorong',
        'manokwari'         => 'Manokwari',
        'fakfak'            => 'Fak-Fak',
        'kaimana'           => 'Kaimana',
        'nabire'            => 'Nabire',
        'mimika'            => 'Mimika',
        'merauke'           => 'Merauke',
        'biak'              => 'Biak',
        'waropen'           => 'Waropen',
        'paniai'            => 'Paniai',
        'puncakjaya'        => 'Puncak Jaya',
        'pegununganarfak'   => 'Pegunungan Arfak',
        'manokwarit'        => 'Manokwari Selatan',
        // ── SHORT / PROVINCE ALIASES ──────────────────────────
        'aceh'              => 'Aceh',
        'dki'               => 'DKI Jakarta',
        'ntb'               => 'NTB',
        'ntt'               => 'NTT',
        'bali'              => 'Bali',
        'sulsel'            => 'Sulawesi Selatan',
        'sulteng'           => 'Sulawesi Tengah',
        'sultra'            => 'Sulawesi Tenggara',
        'sulbar'            => 'Sulawesi Barat',
        'sulut'             => 'Sulawesi Utara',
        'kalsel'            => 'Kalimantan Selatan',
        'kaltim'            => 'Kalimantan Timur',
        'kalteng'           => 'Kalimantan Tengah',
        'kalbar'            => 'Kalimantan Barat',
        'kaltara'           => 'Kalimantan Utara',
        'sumut'             => 'Sumatera Utara',
        'sumbar'            => 'Sumatera Barat',
        'sumsel'            => 'Sumatera Selatan',
        'kepri'             => 'Kepulauan Riau',
        'babel'             => 'Bangka Belitung',
        'jabar'             => 'Jawa Barat',
        'jateng'            => 'Jawa Tengah',
        'jatim'             => 'Jawa Timur',
        'diy'               => 'Yogyakarta',
        'maluku'            => 'Maluku',
        'papua'             => 'Papua',
        'malut'             => 'Maluku Utara',
        'gorut'             => 'Gorontalo Utara',
        'tanjungemas'       => 'Tanjung Emas',
        'tanjungperak'      => 'Tanjung Perak',
        'tanjungpriok'      => 'Tanjung Priok',
        'marunda'           => 'Marunda',
        'malili'            => 'Malili',
        'kanwilbanten'      => 'Kanwil Banten',
        'soroako'           => 'Sorowako',
    ];

    public function __construct(PDO $db, ?EnhancedRateLimiter $rateLimiter = null) {
        $this->db     = $db;
        $this->apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
        if ($rateLimiter === null) {
            $monitor = new SystemMonitor($db);
            $this->rateLimiter = new EnhancedRateLimiter($db, $monitor);
        } else {
            $this->rateLimiter = $rateLimiter;
        }
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS place_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(200) NOT NULL,
            location_slug VARCHAR(200) NOT NULL,
            keyword VARCHAR(100) NOT NULL,
            location_display VARCHAR(200) NOT NULL,
            variants JSON NOT NULL,
            hit_count INT DEFAULT 1,
            last_used DATETIME DEFAULT NOW(),
            scraped_at DATETIME DEFAULT NOW(),
            UNIQUE KEY uk_cache_key (cache_key),
            INDEX idx_slug (location_slug, keyword)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * REGION-FIRST algorithm — mirrors JS parseDomain v20 exactly.
     * 1. Normalize domain → strip TLD(s), protocol, hyphens
     * 2. Find LONGEST matching region inside the slug (greedy)
     * 3. Everything before region = institution part
     * 4. Detect kab/kota modifier in institution part → "Kab. X" / "Kota X"
     */
    public static function parseDomain(string $domain, string $keywordHint = ''): array {
        // Sub-domain segments that are NOT part of the meaningful slug
        static $STRIP_SUBS = ['go','co','sch','ac','or','net','org','web','my',
                               'kemdikbud','kemkes','kemendagri','polri','esdm'];

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];

        // Strip TLDs from the right
        $parts = explode('.', $domain);
        while (count($parts) > 1 && in_array(end($parts), $STRIP_SUBS)) {
            array_pop($parts);
        }
        // Meaningful part = leftmost segment (e.g. "kpai-kabsidoarjo")
        $mainPart = $parts[0] ?? $domain;

        // Normalize: lowercase, remove hyphens
        $slug = strtolower(str_replace(['-','_'], '', $mainPart));

        // KEYWORD HINT LOGIC — Remove institution keyword from slug if provided
        // IMPROVED: Strip keyword from anywhere in slug, not just start
        $instProperHint = '';
        if (!empty($keywordHint)) {
            $kwNorm = strtolower(str_replace(['-','_'], '', trim($keywordHint)));

            // Try to find and remove keyword from slug
            $kwPos = strpos($slug, $kwNorm);
            if ($kwPos !== false) {
                // Found keyword - remove it from slug
                $before = substr($slug, 0, $kwPos);
                $after = substr($slug, $kwPos + strlen($kwNorm));
                $slug = $before . $after;
                $instProperHint = ucfirst($keywordHint);
            }
        }

        static $sortedRegionKeys = null;
        if ($sortedRegionKeys === null) {
            $sortedRegionKeys = array_keys(self::REGION_MAP);
            usort($sortedRegionKeys, fn($a,$b) => strlen($b) - strlen($a));
        }
        $regionKeys = $sortedRegionKeys;

        $regionKey = null;
        $regionPos = -1;
        foreach ($regionKeys as $k) {
            $pos = strpos($slug, $k);
            if ($pos !== false) {
                $regionKey = $k;
                $regionPos = $pos;
                break;
            }
        }

        $regionDisplay = '';
        $geoMod        = '';   // 'kab' | 'kota' | ''
        $instSlug      = '';
        $keyword       = '';

        if ($regionKey !== null) {
            $regionDisplay = self::REGION_MAP[$regionKey];
            $keyword       = strtolower(str_replace([' ','.'], ['-',''], $regionDisplay));

            $before = substr($slug, 0, $regionPos);   // e.g. "kpaikab" / "pormiki"

            // Detect kab/kota RIGHT BEFORE region
            if (preg_match('/kab$/', $before)) {
                $geoMod   = 'kab';
                $instSlug = substr($before, 0, -3);
            } elseif (preg_match('/kota$/', $before)) {
                $geoMod   = 'kota';
                $instSlug = substr($before, 0, -4);
            } elseif (preg_match('/^kab/', $before) && strlen($before) <= 5) {
                $geoMod   = 'kab';
                $instSlug = substr($before, 3);
            } elseif (preg_match('/^kota/', $before) && strlen($before) <= 6) {
                $geoMod   = 'kota';
                $instSlug = substr($before, 4);
            } else {
                $instSlug = $before;
            }
            // Strip trailing digits (sman1 → sman)
            $instSlug = rtrim($instSlug, '0123456789');
        } else {
            // No region found — whole slug is the entity
            $instSlug      = $slug;
            $regionDisplay = ucwords(str_replace(['-','_'], ' ', $slug));
            $keyword       = $slug ?: 'data';
        }

        // Build location_display
        if ($geoMod === 'kab') {
            $locationDisplay = 'Kab. ' . $regionDisplay;
        } elseif ($geoMod === 'kota') {
            $locationDisplay = 'Kota ' . $regionDisplay;
        } else {
            $locationDisplay = $regionDisplay;
        }

        // Institution proper name (use hint if available, else detect from slug)
        $instProper  = $instProperHint ?: self::instToProper($instSlug);
        $searchQuery = $instProper ? "$instProper $locationDisplay" : $locationDisplay;

        // Email: instSlug + region (no geo modifier) + @gmail.com
        $emailBase = $instSlug ? ($instSlug . ($regionKey ?? $slug)) : $slug;
        $email     = $emailBase . '@gmail.com';

        return [
            'full_domain'      => $domain,
            'raw_main'         => $mainPart,
            'keyword'          => $keyword ?: 'data',
            'location_slug'    => $regionKey ?? $slug,
            'location_display' => $locationDisplay,
            'institution'      => $instProper,
            'email_domain'     => $email,
            'search_query'     => $searchQuery,
            'cache_key'        => md5(($keyword ?: 'data') . '|' . ($regionKey ?? $slug)),
        ];
    }

    // Known acronyms → proper display
    private static array $ACRONYMS = [
        'kpai'=>'KPAI','bpbd'=>'BPBD','rsud'=>'RSUD','dinkes'=>'Dinkes',
        'dishub'=>'Dishub','dinas'=>'Dinas','dispora'=>'Dispora','disdik'=>'Disdik','disdikpora'=>'Disdikpora','dispendik'=>'Dispendik','diknas'=>'Diknas','dikpora'=>'Dikpora',
        'diskominfo'=>'Diskominfo','bpjs'=>'BPJS','kpu'=>'KPU','kpud'=>'KPUD',
        'dprd'=>'DPRD','dprk'=>'DPRK','bawaslu'=>'Bawaslu','polres'=>'Polres',
        'polsek'=>'Polsek','pormiki'=>'Pormiki','perbasi'=>'Perbasi',
        'persi'=>'Persi','pdgi'=>'PDGI','idi'=>'IDI','ibi'=>'IBI','ppni'=>'PPNI',
        'hipmi'=>'HIPMI','kadin'=>'KADIN','iwapi'=>'IWAPI','apindo'=>'Apindo',
        'hmi'=>'HMI','pmii'=>'PMII','imm'=>'IMM','knpi'=>'KNPI',
        'bnnp'=>'BNNP','bnnk'=>'BNNK','lpmp'=>'LPMP','lpse'=>'LPSE',
        'mpp'=>'MPP','bkd'=>'BKD','bkpsdm'=>'BKPSDM','bappeda'=>'Bappeda',
        'pemkot'=>'Pemkot','pemkab'=>'Pemkab','sman'=>'SMAN','smpn'=>'SMPN',
        'smkn'=>'SMKN','sdn'=>'SDN','mtsn'=>'MTsN','man'=>'MAN',
        'uin'=>'UIN','iain'=>'IAIN','pss'=>'PSS','pssi'=>'PSSI',
        'porbasi'=>'Porbasi','damkar'=>'Damkar','bpbd'=>'BPBD',
        'beacukai'=>'Bea Cukai','pengadilan'=>'Pengadilan',
        'kejaksaan'=>'Kejaksaan','puskesmas'=>'Puskesmas',
        'aptisi'=>'APTISI','butikemas'=>'Butikemas',
    ];

    public static function instToProper(string $s): string {
        if (!$s) return '';
        if (isset(self::$ACRONYMS[$s])) return self::$ACRONYMS[$s];
        return ucfirst($s);
    }

    public static function slugToDisplay(string $slug): string {
        // Legacy helper — delegates to new parseDomain logic via REGION_MAP lookup
        $slug = strtolower(trim($slug));
        if (isset(self::REGION_MAP[$slug])) return self::REGION_MAP[$slug];
        $best = ''; $blen = 0;
        foreach (self::REGION_MAP as $k => $v) {
            if (strlen($k) >= 4 && strpos($slug, $k) !== false && strlen($k) > $blen) {
                $best = $v; $blen = strlen($k);
            }
        }
        if ($best) return $best;
        return ucwords(str_replace(['-','_'], ' ', $slug));
    }

    /**
     * @param bool $forceRefresh  Always scrape, skip cache lookup
     */
    public function getData(array $parsed, bool $forceRefresh = false): array {
        $cacheKey = $parsed['cache_key'];
        $variants = [];

        if (!$forceRefresh) {
            $stmt = $this->db->prepare("SELECT variants FROM place_cache WHERE cache_key=?");
            $stmt->execute([$cacheKey]);
            $row = $stmt->fetch();
            if ($row) {
                $this->db->prepare("UPDATE place_cache SET hit_count=hit_count+1, last_used=NOW() WHERE cache_key=?")->execute([$cacheKey]);
                $variants = json_decode($row['variants'], true);
            }
        }

        if (empty($variants)) {
            $variants = $this->scrapeVariants($parsed);
            if (!empty($variants)) {
                $this->db->prepare("INSERT INTO place_cache (cache_key,location_slug,keyword,location_display,variants,scraped_at) VALUES(?,?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE variants=VALUES(variants), scraped_at=NOW()"
                )->execute([$cacheKey, $parsed['location_slug'], $parsed['keyword'],
                             $parsed['location_display'], json_encode($variants)]);
            }
        }

        $v = !empty($variants) ? $variants[array_rand($variants)] : [];

        // Build daerah label based on location_level from AI
        $level    = $parsed['location_level'] ?? 'kota';
        $daerahLabel = $parsed['location_display'];
        if (!empty($parsed['province']) && $parsed['province'] !== $parsed['location_display']) {
            if (in_array($level, ['kecamatan','kelurahan'])) {
                $daerahLabel = $parsed['location_display'] . ', ' . $parsed['province'];
            }
        }

        return [
            'namalink'         => $parsed['full_domain'],
            'daerah'           => $daerahLabel,
            'daerah_short'     => $parsed['location_display'],
            'provinsi'         => $parsed['province'] ?? '',
            'level'            => $level,
            'institution'      => $parsed['institution'] ?? '',
            'institution_full' => $parsed['institution_full'] ?? $parsed['institution'] ?? '',
            'email'            => $v['email']    ?? $parsed['email_domain'],
            'alamat'           => $v['alamat']   ?? $this->fallbackAlamat($parsed),
            'kodepos'          => $v['kodepos']  ?? $this->fallbackKodepos($parsed),
            'embedmap'         => $v['embedmap'] ?? $this->fallbackEmbed($parsed),
            'linkmaps'         => $v['linkmaps'] ?? $this->fallbackLink($parsed),
            'source'           => $v['source']   ?? 'fallback',
            'place_name'       => $v['place_name'] ?? '',
            'rating'           => $v['rating']   ?? '',
        ];
    }

    private function scrapeVariants(array $parsed): array {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            return $this->generateFallbackVariants($parsed);
        }

        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
            'query'    => $parsed['search_query'],
            'key'      => $this->apiKey,
            'language' => 'id',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $resp = curl_exec($ch);
        curl_close($ch);

        if (!$resp) return $this->generateFallbackVariants($parsed);
        $json = json_decode($resp, true);
        if (($json['status'] ?? '') !== 'OK' || empty($json['results'])) {
            return $this->generateFallbackVariants($parsed);
        }

        $variants = [];
        foreach (array_slice($json['results'], 0, 8) as $r) {
            $lat  = $r['geometry']['location']['lat'] ?? 0;
            $lng  = $r['geometry']['location']['lng'] ?? 0;
            $pid  = $r['place_id'] ?? '';
            $addr = $r['formatted_address'] ?? $r['vicinity'] ?? '';
            if (!$addr) continue;
            $variants[] = [
                'place_name' => $r['name'] ?? '',
                'alamat'     => $addr,
                'embedmap'   => $this->buildEmbed($lat, $lng, $pid, $r['name'] ?? ''),
                'linkmaps'   => $pid
                    ? "https://www.google.com/maps/place/?q=place_id:{$pid}"
                    : "https://maps.google.com/@{$lat},{$lng},17z",
                'source'     => 'google',
            ];
        }

        if (count($variants) < 3) {
            $fb       = $this->generateFallbackVariants($parsed);
            $variants = array_merge($variants, array_slice($fb, 0, 5 - count($variants)));
        }
        return $variants;
    }

    private function generateFallbackVariants(array $parsed): array {
        $h     = crc32($parsed['location_slug'] ?: $parsed['keyword'] ?: 'id');
        $disp  = $parsed['location_display'];
        $prov  = $parsed['province'] ?? '';
        $level = $parsed['location_level'] ?? 'kota';
        $inst  = $parsed['institution_full'] ?? $parsed['institution'] ?? strtoupper($parsed['keyword'] ?? 'Kantor');

        // Street pool — varied and realistic
        $streets = [
            'Jl. Jenderal Sudirman','Jl. Ahmad Yani','Jl. Merdeka','Jl. Diponegoro',
            'Jl. Jend. Ahmad Yani','Jl. Pahlawan','Jl. Pemuda','Jl. Veteran',
            'Jl. Pramuka','Jl. Hasanuddin','Jl. Teuku Umar','Jl. Imam Bonjol',
            'Jl. Gadjah Mada','Jl. Cut Meutia','Jl. Kartini','Jl. Gajah Mada',
            'Jl. S. Parman','Jl. Raya','Jl. Patimura','Jl. Wolter Monginsidi',
            'Jl. MT. Haryono','Jl. Brigjen Katamso','Jl. Kapten Tendean',
        ];
        // Province-aware kodepos base
        $zipBase = match(true) {
            str_contains($prov,'Jakarta')    => 10000,
            str_contains($prov,'Jawa Barat') => 40000,
            str_contains($prov,'Jawa Tengah')=> 50000,
            str_contains($prov,'Jawa Timur') => 60000,
            str_contains($prov,'Sumatera Utara') => 20000,
            str_contains($prov,'Sulawesi Selatan') => 90000,
            str_contains($prov,'Kalimantan') => 70000,
            str_contains($prov,'Bali')       => 80000,
            default                          => 30000,
        };
        // Province-aware lat/lng center
        $latBase = match(true) {
            str_contains($prov,'Jakarta')    => -6.20,
            str_contains($prov,'Jawa Barat') => -6.90,
            str_contains($prov,'Jawa Tengah')=> -7.15,
            str_contains($prov,'Jawa Timur') => -7.50,
            str_contains($prov,'Sumatera Utara') => 3.50,
            str_contains($prov,'Sulawesi Selatan') => -5.10,
            str_contains($prov,'Kalimantan') => -0.50,
            str_contains($prov,'Bali')       => -8.65,
            str_contains($prov,'Aceh')       => 4.60,
            default                          => -6.50 + ($h % 300) / 100.0,
        };
        $lngBase = match(true) {
            str_contains($prov,'Jakarta')    => 106.82,
            str_contains($prov,'Jawa Barat') => 107.60,
            str_contains($prov,'Jawa Tengah')=> 110.40,
            str_contains($prov,'Jawa Timur') => 112.75,
            str_contains($prov,'Sumatera Utara') => 98.67,
            str_contains($prov,'Sulawesi Selatan') => 119.42,
            str_contains($prov,'Kalimantan') => 114.00,
            str_contains($prov,'Bali')       => 115.21,
            str_contains($prov,'Aceh')       => 95.32,
            default                          => 107.00 + ($h % 400) / 200.0,
        };

        $vars = [];
        for ($i = 0; $i < 6; $i++) {
            $si   = abs($h + $i * 7)  % count($streets);
            $no   = 1  + abs($h + $i * 13) % 99;
            $zip  = $zipBase + abs($h + $i * 17) % 999;
            $lat  = $latBase  + ($i * 0.004) + (($h & 0xff) * 0.0001);
            $lng  = $lngBase  + ($i * 0.005) + (($h & 0x0f) * 0.0002);

            // Build alamat - include province for kecamatan/kelurahan
            $alamatLoc = $disp;
            if ($prov && $prov !== $disp && in_array($level, ['kecamatan','kelurahan'])) {
                $alamatLoc = $disp . ', ' . $prov;
            } elseif ($prov && $prov !== $disp) {
                $alamatLoc = $disp . ', ' . $prov;
            }

            $vars[] = [
                'place_name' => "{$inst} {$disp}",
                'alamat'     => "{$streets[$si]} No.{$no}, {$alamatLoc} {$zip}",
                'kodepos'    => (string)$zip,
                'email'      => $parsed['email_domain'] ?? '',
                'embedmap'   => $this->buildEmbed(
                    round($lat, 6), round($lng, 6),
                    '',
                    "{$inst} {$disp}"
                ),
                'linkmaps'   => 'https://maps.google.com/?q=' . rawurlencode("{$inst} {$disp}"),
                'source'     => 'fallback',
                'rating'     => '',
            ];
        }
        return $vars;
    }

    private function extractKodepos(string $address): string {
        // Try to extract 5-digit Indonesian postal code from address
        if (preg_match('/\b(\d{5})\b/', $address, $m)) {
            return $m[1];
        }
        return '';
    }

    private function fallbackKodepos(array $p): string {
        $prov = $p['province'] ?? '';
        $h    = crc32($p['location_slug'] ?? 'id');
        $base = match(true) {
            str_contains($prov,'Jakarta')    => 10000,
            str_contains($prov,'Jawa Barat') => 40000,
            str_contains($prov,'Jawa Tengah')=> 50000,
            str_contains($prov,'Jawa Timur') => 60000,
            str_contains($prov,'Sumatera Utara') => 20000,
            str_contains($prov,'Sulawesi Selatan') => 90000,
            str_contains($prov,'Kalimantan') => 70000,
            str_contains($prov,'Bali')       => 80000,
            default                          => 30000,
        };
        return (string)($base + abs($h) % 999);
    }

    private function buildEmbed(float $lat, float $lng, string $pid, string $name): string {
        $d  = 15000;
        $ts = time() . rand(100, 999);
        $pe = $pid ? '!1s' . rawurlencode($pid) : '!1s0x0';
        $ne = rawurlencode($name);
        return "https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d{$d}!2d{$lng}!3d{$lat}!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!{$pe}!2s{$ne}!5e0!3m2!1sid!2sid!4v{$ts}!5m2!1sid!2sid";
    }

    private function fallbackAlamat(array $p): string {
        $h  = crc32($p['location_slug']);
        $st = ['Jl. Jenderal Sudirman','Jl. Ahmad Yani','Jl. Merdeka','Jl. Diponegoro','Jl. Pahlawan'];
        return $st[abs($h) % count($st)] . ' No.' . (1 + abs($h >> 2) % 299) . ', ' . $p['location_display'] . ', Indonesia';
    }
    private function fallbackEmbed(array $p): string {
        // Generate embed URL compatible with most iframe implementations
        // Format: https://maps.google.com/maps?q=QUERY&output=embed&z=15
        $q = rawurlencode($p['search_query'] ?? $p['location_display'] ?? 'Indonesia');
        return 'https://maps.google.com/maps?q='.$q.'&output=embed&z=15&hl=id';
    }
    private function fallbackLink(array $p): string {
        return 'https://maps.google.com/?q=' . rawurlencode($p['search_query']);
    }

    /**
     * PARALLEL BATCH SCRAPER — 10x faster!
     * Process multiple domains simultaneously using curl_multi
     */
    public function getDataBatch(array $parsedList, bool $forceRefresh = false, $progressCallback = null): array {
        $results = [];
        $toScrape = [];

        // First pass: check cache
        foreach ($parsedList as $idx => $parsed) {
            $cacheKey = $parsed['cache_key'];

            if (!$forceRefresh) {
                $stmt = $this->db->prepare("SELECT variants FROM place_cache WHERE cache_key=?");
                $stmt->execute([$cacheKey]);
                $row = $stmt->fetch();

                if ($row) {
                    // Cache hit!
                    $this->db->prepare("UPDATE place_cache SET hit_count=hit_count+1, last_used=NOW() WHERE cache_key=?")->execute([$cacheKey]);
                    $variants = json_decode($row['variants'], true);
                    $results[$idx] = $this->buildResult($parsed, $variants);

                    if ($progressCallback) {
                        call_user_func($progressCallback, $idx, 'cache_hit');
                    }
                    continue;
                }
            }

            // Cache miss - need to scrape
            $toScrape[$idx] = $parsed;
        }

        // Second pass: parallel scrape for cache misses
        if (!empty($toScrape)) {
            $scrapedResults = $this->parallelScrape($toScrape, $progressCallback);

            foreach ($scrapedResults as $idx => $variants) {
                $parsed = $toScrape[$idx];

                // Save to cache
                if (!empty($variants)) {
                    $this->db->prepare("INSERT INTO place_cache (cache_key,location_slug,keyword,location_display,variants,scraped_at) VALUES(?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE variants=VALUES(variants), scraped_at=NOW()"
                    )->execute([
                        $parsed['cache_key'],
                        $parsed['location_slug'],
                        $parsed['keyword'],
                        $parsed['location_display'],
                        json_encode($variants)
                    ]);
                }

                $results[$idx] = $this->buildResult($parsed, $variants);
            }
        }

        // Sort by original index
        ksort($results);
        return array_values($results);
    }

    /**
     * Parallel scrape using curl_multi — processes 10 domains at once!
     */
    private function parallelScrape(array $parsedList, $progressCallback = null): array {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            // Fallback mode
            $results = [];
            foreach ($parsedList as $idx => $parsed) {
                $results[$idx] = $this->generateFallbackVariants($parsed);
                if ($progressCallback) {
                    call_user_func($progressCallback, $idx, 'fallback');
                }
            }
            return $results;
        }

        $results = [];
        $batchSize = 10; // Process 10 at once
        $chunks = array_chunk($parsedList, $batchSize, true);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            // Init curl handles for each domain in chunk
            foreach ($chunk as $idx => $parsed) {
                $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
                    'query'    => $parsed['search_query'],
                    'key'      => $this->apiKey,
                    'language' => 'id',
                ]);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = ['handle' => $ch, 'parsed' => $parsed];
            }

            // Execute all handles simultaneously
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            // Collect results
            foreach ($handles as $idx => $data) {
                $ch = $data['handle'];
                $parsed = $data['parsed'];
                $resp = curl_multi_getcontent($ch);

                $variants = [];
                if ($resp) {
                    $json = json_decode($resp, true);
                    if (($json['status'] ?? '') === 'OK' && !empty($json['results'])) {
                        $variants = $this->parseGoogleResults($json['results'], $parsed);
                    }
                }

                if (empty($variants)) {
                    $variants = $this->generateFallbackVariants($parsed);
                }

                $results[$idx] = $variants;

                if ($progressCallback) {
                    call_user_func($progressCallback, $idx, 'scraped');
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * Parse Google Places API results into variants
     */
    private function parseGoogleResults(array $results, array $parsed): array {
        $variants = [];
        foreach (array_slice($results, 0, 8) as $r) {
            $lat  = $r['geometry']['location']['lat'] ?? 0;
            $lng  = $r['geometry']['location']['lng'] ?? 0;

            $variants[] = [
                'place_name' => $r['name'] ?? '',
                'alamat'     => $r['formatted_address'] ?? '',
                'kodepos'    => $this->extractKodepos($r['formatted_address'] ?? ''),
                'embedmap'   => "https://www.google.com/maps/embed/v1/place?key={$this->apiKey}&q={$lat},{$lng}",
                'linkmaps'   => "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}",
                'email'      => $parsed['email_domain'],
                'source'     => 'google_places',
                'rating'     => $r['rating'] ?? '',
            ];
        }
        return $variants;
    }

    /**
     * Build final result from parsed + variants
     */
    private function buildResult(array $parsed, array $variants): array {
        $v = !empty($variants) ? $variants[array_rand($variants)] : [];

        $level = $parsed['location_level'] ?? 'kota';
        $daerahLabel = $parsed['location_display'];
        if (!empty($parsed['province']) && $parsed['province'] !== $parsed['location_display']) {
            if (in_array($level, ['kecamatan','kelurahan'])) {
                $daerahLabel = $parsed['location_display'] . ', ' . $parsed['province'];
            }
        }

        return [
            'namalink'         => $parsed['full_domain'],
            'daerah'           => $daerahLabel,
            'daerah_short'     => $parsed['location_display'],
            'provinsi'         => $parsed['province'] ?? '',
            'level'            => $level,
            'institution'      => $parsed['institution'] ?? '',
            'institution_full' => $parsed['institution_full'] ?? $parsed['institution'] ?? '',
            'email'            => $v['email']    ?? $parsed['email_domain'],
            'alamat'           => $v['alamat']   ?? $this->fallbackAlamat($parsed),
            'kodepos'          => $v['kodepos']  ?? $this->fallbackKodepos($parsed),
            'embedmap'         => $v['embedmap'] ?? $this->fallbackEmbed($parsed),
            'linkmaps'         => $v['linkmaps'] ?? $this->fallbackLink($parsed),
            'source'           => $v['source']   ?? 'fallback',
            'place_name'       => $v['place_name'] ?? '',
            'rating'           => $v['rating']   ?? '',
        ];
    }

    public function checkCacheBatch(array $parsedList): array {
        $result = [];
        foreach ($parsedList as $p) {
            $stmt = $this->db->prepare("SELECT hit_count, scraped_at FROM place_cache WHERE cache_key=?");
            $stmt->execute([$p['cache_key']]);
            $row = $stmt->fetch();
            $result[$p['full_domain']] = $row
                ? ['cached'=>true,'hits'=>$row['hit_count'],'at'=>$row['scraped_at']]
                : ['cached'=>false];
        }
        return $result;
    }

    public function getCacheStats(): array {
        $row = $this->db->query("SELECT COUNT(*) c, COALESCE(SUM(hit_count),0) h FROM place_cache")->fetch();
        return ['locations' => (int)($row['c']??0), 'hits' => (int)($row['h']??0)];
    }
}
