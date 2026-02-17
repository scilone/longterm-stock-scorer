<?php

declare(strict_types=1);

const SCORING_NORMALIZATION_ENABLED = true;
const VERBOSE = false;

const HIGH_YIELD_DIVIDEND_PENALTY_ENABLED = true;
const HIGH_YIELD_DIVIDEND_PENALTY_POINTS = 1;

const FINANCIAL_OVERALLRISK_SAFETY_PENALTY_ENABLED = true;
const FINANCIAL_OVERALLRISK_SAFETY_THRESHOLDS = [[7, 1]];

const EXTREME_PAYOUT_GATE_ENABLED = true;
const EXTREME_PAYOUT_THRESHOLDS = [
    [150, ['dividend_to' => 0, 'safety_minus' => 1]],
    [100, ['dividend_cap' => 1]],
];

const COMPOUNDER_QUALITY_BOOST_ENABLED = true;
const COMPOUNDER_QUALITY_BOOST_BLOCK = 'Quality';
const COMPOUNDER_QUALITY_BOOST_POINTS = 1;

const COMPOUNDER_QUALITY_CRITERIA = [
    'roa_min' => 0.065,
    'beta_max' => 0.9,
    'payout_min' => 30.0,
    'payout_max' => 70.0,
    'safety_min' => 3,
];

const SPECIAL_DIVIDEND_DETECTION_ENABLED = true;
const SPECIAL_DIVIDEND_THRESHOLD_PCT = 20.0;

const SPECIAL_DIVIDEND_PENALTY = [
    'max_dividend_score' => 2,
];

const RECOMMENDATION_ENABLED = true;
const RECOMMENDATION_THRESHOLDS = [
    'ACHETER' => ['score_min' => 16, 'score_max' => 20, 'description' => "Excellence exceptionnelle - Opportunité d'accumulation prioritaire"],
    'RENFORCER' => ['score_min' => 14, 'score_max' => 15, 'description' => 'Très bonne qualité - Accumuler progressivement sur replis'],
    'CONSERVER' => ['score_min' => 12, 'score_max' => 13, 'description' => 'Cœur de portefeuille - Tenir long terme, ne pas vendre'],
    'ALLEGER' => ['score_min' => 9, 'score_max' => 11, 'description' => 'Qualité correcte mais pas prioritaire - Réduire si surpondéré'],
    'VENDRE' => ['score_min' => 0, 'score_max' => 8, 'description' => 'Qualité insuffisante ou risque élevé - Sortir progressivement'],
];

const PAYOUT_UNSUSTAINABLE_MIN = 0.95;
const HIGH_YIELD_ALERT_MIN = 0.08;
const EXTREME_PEG_LOW_MAX = 0.20;
const EXTREME_PEG_HIGH_MIN = 3.00;
const SPECIAL_DIV_MULTIPLIER = 1.25;
const MISSING_DATA_MIN_FIELDS = 3;

const QUALITY_ROA_THRESHOLDS = [0.02, 0.05];
const QUALITY_ROE_THRESHOLDS = [0.08, 0.12, 0.15];
const SAFETY_BETA_THRESHOLDS = [0.7, 1.2];
const SAFETY_DEBT_RATIO_THRESHOLD = 2.0;
const PEGY_THRESHOLDS = [0.5, 1.0];
const PE_FWD_THRESHOLD = 15;
const DIVIDEND_YIELD_THRESHOLDS = [0.02, 0.04, 0.06];
const DIVIDEND_PAYOUT_MAX = 0.8;

const DEFAULT_PORTFOLIO = [
    'FR0000120271','FR0000120503','FR0000125486','FR0000133308','FR0010208488','FR0000120578','FR0000121261','FR0000130577','FR0000124141','NL0000235190',
    'FR0000131104','FR0000045072','FR0000130809','FR0000120628','NL0011623188','IT0000072618','NL0011821202','NL0011540547','DE0005140008','ES0140609019',
];

function safe_get($d, string $key, $default = null) {
    return is_array($d) && array_key_exists($key, $d) ? $d[$key] : $default;
}

function is_missing($x): bool {
    return $x === null;
}

function normalize_yield_fraction($y) {
    if ($y === null) {
        return null;
    }
    return $y > 1.0 ? ($y / 100.0) : $y;
}

function normalize_payout_ratio($p) {
    if ($p === null) {
        return null;
    }
    return $p >= 0 ? $p : null;
}

function detect_special_dividend($yield_pct): bool {
    return SPECIAL_DIVIDEND_DETECTION_ENABLED && $yield_pct !== null && $yield_pct > SPECIAL_DIVIDEND_THRESHOLD_PCT;
}

function get_profile_max_total(string $profile): int {
    return $profile === 'Financial' ? 20 : 17;
}

function normalize_score(int $score_raw, string $profile): int {
    if (!SCORING_NORMALIZATION_ENABLED) {
        return $score_raw;
    }
    $max_total = get_profile_max_total($profile);
    $normalized = (int) round($score_raw * 20 / $max_total);
    return max(0, min(20, $normalized));
}

function get_recommendation(int $score, array $flags): ?array {
    if (!RECOMMENDATION_ENABLED) {
        return null;
    }

    $effective = ($flags['special_dividend'] ?? false) && $score >= 12 ? ($score - 1) : $score;
    $action = null;
    $description = null;

    foreach (RECOMMENDATION_THRESHOLDS as $recoAction => $config) {
        if ($effective >= $config['score_min'] && $effective <= $config['score_max']) {
            $action = $recoAction;
            $description = $config['description'];
            break;
        }
    }

    if ($action === null) {
        return ['action' => 'INDEFINI', 'description' => 'Score hors limites'];
    }

    $warnings = [];
    if (($flags['payout_unsustainable'] ?? false) && $score >= 14) {
        $warnings[] = '⚠️ Surveiller dividende (payout élevé)';
    }
    if (($flags['high_yield_alert'] ?? false) && in_array($action, ['ACHETER', 'RENFORCER'], true)) {
        $warnings[] = '⚠️ Yield extrême, vérifier durabilité';
    }
    if (($flags['special_dividend'] ?? false) && $score >= 12) {
        $warnings[] = '⚠️ Dividende exceptionnel détecté';
    }

    $result = ['action' => $action, 'description' => $description];
    if ($warnings) {
        $result['warnings'] = $warnings;
    }
    return $result;
}

function is_financial_firm(array $info): bool {
    $sector = strtolower((string) safe_get($info, 'sector', ''));
    $industry = strtolower((string) safe_get($info, 'industry', ''));
    foreach (['financial', 'bank', 'insurance', 'capital markets', 'asset management', 'credit services'] as $kw) {
        if (str_contains($sector, $kw) || str_contains($industry, $kw)) {
            return true;
        }
    }
    return false;
}

function compute_pegy_standard(array $info): ?float {
    $pe_fwd = safe_get($info, 'forwardPE');
    $eps_current = safe_get($info, 'epsCurrentYear');
    $eps_forward = safe_get($info, 'epsForward');
    if (!$pe_fwd || !$eps_current || !$eps_forward || $eps_current <= 0) {
        return null;
    }

    $growth = ($eps_forward - $eps_current) / $eps_current;
    if ($growth <= 0) {
        return null;
    }
    return $pe_fwd / ($growth * 100);
}

function score_quality_standard(array $info): int {
    $roa = safe_get($info, 'returnOnAssets');
    if ($roa === null) {
        return 1;
    }
    if ($roa >= QUALITY_ROA_THRESHOLDS[1]) {
        return 2;
    }
    if ($roa >= QUALITY_ROA_THRESHOLDS[0]) {
        return 1;
    }
    return 0;
}

function score_safety_standard(array $info): int {
    $score = 0;

    $beta = safe_get($info, 'beta');
    if ($beta !== null) {
        if ($beta < SAFETY_BETA_THRESHOLDS[0]) {
            $score += 2;
        } elseif ($beta < SAFETY_BETA_THRESHOLDS[1]) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    $total_debt = safe_get($info, 'totalDebt');
    $ebitda = safe_get($info, 'ebitda');
    if ($total_debt !== null && $ebitda !== null && $ebitda > 0) {
        $debt_ratio = $total_debt / $ebitda;
        if ($debt_ratio < SAFETY_DEBT_RATIO_THRESHOLD) {
            $score += 3;
        } elseif ($debt_ratio < SAFETY_DEBT_RATIO_THRESHOLD * 1.5) {
            $score += 2;
        } else {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    return min($score, 5);
}

function score_value_growth_standard(array $info): int {
    $pegy = compute_pegy_standard($info);
    $pe_fwd = safe_get($info, 'forwardPE');
    $score = 0;

    if ($pegy !== null) {
        if ($pegy <= PEGY_THRESHOLDS[0]) {
            $score += 3;
        } elseif ($pegy <= PEGY_THRESHOLDS[1]) {
            $score += 2;
        } else {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($pe_fwd !== null) {
        if ($pe_fwd < PE_FWD_THRESHOLD) {
            $score += 2;
        } elseif ($pe_fwd < PE_FWD_THRESHOLD * 1.5) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    return min($score, 5);
}

function score_dividend_standard(array $info): int {
    $div_yield = normalize_yield_fraction(safe_get($info, 'dividendYield'));
    $payout = normalize_payout_ratio(safe_get($info, 'payoutRatio'));
    $score = 0;

    if ($div_yield !== null) {
        if ($div_yield >= DIVIDEND_YIELD_THRESHOLDS[2]) {
            $score += 3;
        } elseif ($div_yield >= DIVIDEND_YIELD_THRESHOLDS[1]) {
            $score += 2;
        } elseif ($div_yield >= DIVIDEND_YIELD_THRESHOLDS[0]) {
            $score += 1;
        }
    }

    if ($payout !== null) {
        if ($payout <= DIVIDEND_PAYOUT_MAX) {
            $score += 2;
        } elseif ($payout <= 1.0) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    return min($score, 5);
}

function score_quality_financial(array $info): int {
    $roe = safe_get($info, 'returnOnEquity');
    $pb = safe_get($info, 'priceToBook');
    $score = 0;

    if ($roe !== null) {
        if ($roe >= QUALITY_ROE_THRESHOLDS[2]) {
            $score += 3;
        } elseif ($roe >= QUALITY_ROE_THRESHOLDS[1]) {
            $score += 2;
        } elseif ($roe >= QUALITY_ROE_THRESHOLDS[0]) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($pb !== null) {
        if ($pb < 1.0) {
            $score += 2;
        } elseif ($pb < 1.5) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    return min($score, 5);
}

function score_safety_financial(array $info): int {
    $beta = safe_get($info, 'beta');
    $dte = safe_get($info, 'debtToEquity');
    $risk = safe_get($info, 'overallRisk');
    $score = 0;

    if ($beta !== null) {
        if ($beta < SAFETY_BETA_THRESHOLDS[0]) {
            $score += 2;
        } elseif ($beta < SAFETY_BETA_THRESHOLDS[1]) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($dte !== null) {
        if ($dte < 100) {
            $score += 2;
        } elseif ($dte < 150) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($risk !== null && $risk <= 2) {
        $score += 1;
    }

    return min($score, 5);
}

function score_value_growth_financial(array $info): int {
    $pe_fwd = safe_get($info, 'forwardPE');
    $pe_ttm = safe_get($info, 'trailingPE');
    $pb = safe_get($info, 'priceToBook');
    $score = 0;

    if ($pe_fwd !== null) {
        if ($pe_fwd < 10) {
            $score += 2;
        } elseif ($pe_fwd < 12) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($pe_ttm !== null) {
        if ($pe_ttm < 12) {
            $score += 2;
        } elseif ($pe_ttm < 15) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    if ($pb !== null && $pb < 0.8) {
        $score += 1;
    }

    return min($score, 5);
}

function score_dividend_financial(array $info): int {
    $div_yield = normalize_yield_fraction(safe_get($info, 'dividendYield'));
    $payout = normalize_payout_ratio(safe_get($info, 'payoutRatio'));
    $score = 0;

    if ($div_yield !== null) {
        if ($div_yield >= 0.05) {
            $score += 3;
        } elseif ($div_yield >= 0.03) {
            $score += 2;
        } elseif ($div_yield >= 0.02) {
            $score += 1;
        }
    }

    if ($payout !== null) {
        if ($payout <= 0.7) {
            $score += 2;
        } elseif ($payout <= 0.9) {
            $score += 1;
        }
    } else {
        $score += 1;
    }

    return min($score, 5);
}

function build_raw_standard(array $info): array {
    $div = normalize_yield_fraction(safe_get($info, 'dividendYield'));
    $payout = normalize_payout_ratio(safe_get($info, 'payoutRatio'));

    return [
        'yield_pct' => $div ? round($div * 100, 2) : null,
        'payout_pct' => $payout ? round($payout * 100, 2) : null,
        'pegy' => compute_pegy_standard($info),
        'roa' => safe_get($info, 'returnOnAssets'),
        'totalDebt' => safe_get($info, 'totalDebt'),
        'totalCash' => safe_get($info, 'totalCash'),
        'ebitda' => safe_get($info, 'ebitda'),
        'beta' => safe_get($info, 'beta'),
    ];
}

function build_raw_financial(array $info): array {
    $div = normalize_yield_fraction(safe_get($info, 'dividendYield'));
    $payout = normalize_payout_ratio(safe_get($info, 'payoutRatio'));

    return [
        'roe' => safe_get($info, 'returnOnEquity'),
        'pb' => safe_get($info, 'priceToBook'),
        'pe_fwd' => safe_get($info, 'forwardPE'),
        'pe_ttm' => safe_get($info, 'trailingPE'),
        'beta' => safe_get($info, 'beta'),
        'debtToEquity' => safe_get($info, 'debtToEquity'),
        'overallRisk' => safe_get($info, 'overallRisk'),
        'yield_pct' => $div ? round($div * 100, 2) : null,
        'payout_pct' => $payout ? round($payout * 100, 2) : null,
    ];
}

function compute_flags(array $info, string $profile, array $raw): array {
    $flags = [
        'missing_data' => false,
        'missing_fields' => [],
        'payout_unsustainable' => false,
        'special_dividend' => false,
        'extreme_peg' => false,
        'high_yield_alert' => false,
    ];

    $required = $profile === 'Financial'
        ? ['roe', 'pb', 'pe_fwd', 'pe_ttm', 'beta', 'overallRisk', 'yield_pct', 'payout_pct']
        : ['roa', 'totalDebt', 'totalCash', 'ebitda', 'beta', 'yield_pct', 'payout_pct', 'pegy'];

    $missing = [];
    foreach ($required as $k) {
        if (is_missing(safe_get($raw, $k))) {
            $missing[] = $k;
        }
    }
    $flags['missing_fields'] = $missing;
    $flags['missing_data'] = count($missing) >= MISSING_DATA_MIN_FIELDS;

    $payout_pct = safe_get($raw, 'payout_pct');
    if ($payout_pct !== null) {
        $flags['payout_unsustainable'] = $payout_pct >= PAYOUT_UNSUSTAINABLE_MIN * 100;
    }

    $y = safe_get($raw, 'yield_pct');
    if ($y !== null) {
        $flags['high_yield_alert'] = $y >= HIGH_YIELD_ALERT_MIN * 100;
    }
    if (detect_special_dividend($y)) {
        $flags['special_dividend'] = true;
    }

    if ($profile === 'Standard') {
        $pegy = safe_get($raw, 'pegy');
        if ($pegy !== null) {
            $flags['extreme_peg'] = $pegy <= EXTREME_PEG_LOW_MAX || $pegy >= EXTREME_PEG_HIGH_MIN;
        }
    }

    $dividend_rate = safe_get($info, 'dividendRate');
    $trailing_div_rate = safe_get($info, 'trailingAnnualDividendRate');
    $dividend_yield = normalize_yield_fraction(safe_get($info, 'dividendYield'));
    $trailing_div_yield = normalize_yield_fraction(safe_get($info, 'trailingAnnualDividendYield'));

    if ($dividend_rate && $trailing_div_rate && $trailing_div_rate > $dividend_rate * SPECIAL_DIV_MULTIPLIER) {
        $flags['special_dividend'] = true;
    }
    if ($dividend_yield && $trailing_div_yield && $trailing_div_yield > $dividend_yield * SPECIAL_DIV_MULTIPLIER) {
        $flags['special_dividend'] = true;
    }
    if ($flags['high_yield_alert'] && $payout_pct === null) {
        $flags['special_dividend'] = true;
    }

    return $flags;
}

function apply_all_penalties(string $profile, array $blocks, array $flags, array $raw): array {
    if (($flags['special_dividend'] ?? false) && SPECIAL_DIVIDEND_DETECTION_ENABLED && isset($blocks['Dividend'])) {
        $max_div = safe_get(SPECIAL_DIVIDEND_PENALTY, 'max_dividend_score', 2);
        $blocks['Dividend'] = min($blocks['Dividend'], $max_div);
    }

    if (($flags['high_yield_alert'] ?? false) && HIGH_YIELD_DIVIDEND_PENALTY_ENABLED && isset($blocks['Dividend'])) {
        $blocks['Dividend'] = max(0, $blocks['Dividend'] - HIGH_YIELD_DIVIDEND_PENALTY_POINTS);
    }

    if ($profile === 'Financial' && FINANCIAL_OVERALLRISK_SAFETY_PENALTY_ENABLED) {
        $risk = safe_get($raw, 'overallRisk');
        if ($risk !== null) {
            foreach (FINANCIAL_OVERALLRISK_SAFETY_THRESHOLDS as $rule) {
                [$threshold, $penalty] = $rule;
                if ($risk >= $threshold) {
                    $blocks['Safety'] = max(0, $blocks['Safety'] - $penalty);
                    break;
                }
            }
        }
    }

    if ($profile === 'Standard' && EXTREME_PAYOUT_GATE_ENABLED) {
        $payout_pct = safe_get($raw, 'payout_pct');
        if ($payout_pct !== null) {
            foreach (EXTREME_PAYOUT_THRESHOLDS as $rule) {
                [$threshold, $action] = $rule;
                if ($payout_pct >= $threshold) {
                    if (isset($action['dividend_to'])) {
                        $blocks['Dividend'] = $action['dividend_to'];
                    }
                    if (isset($action['dividend_cap'])) {
                        $blocks['Dividend'] = min($blocks['Dividend'], $action['dividend_cap']);
                    }
                    if (isset($action['safety_minus'])) {
                        $blocks['Safety'] = max(0, $blocks['Safety'] - $action['safety_minus']);
                    }
                    break;
                }
            }
        }
    }

    return $blocks;
}

function apply_compounder_boost(string $profile, array $blocks, array $raw): array {
    if (!COMPOUNDER_QUALITY_BOOST_ENABLED || $profile !== 'Standard') {
        return $blocks;
    }

    $roa = safe_get($raw, 'roa');
    $beta = safe_get($raw, 'beta');
    $payout = safe_get($raw, 'payout_pct');
    $safety = safe_get($blocks, 'Safety', 0);

    if ($roa === null || $beta === null || $payout === null) {
        return $blocks;
    }

    $c = COMPOUNDER_QUALITY_CRITERIA;
    if ($roa >= $c['roa_min'] &&
        $beta < $c['beta_max'] &&
        $payout >= $c['payout_min'] &&
        $payout <= $c['payout_max'] &&
        $safety >= $c['safety_min']) {
        $block = COMPOUNDER_QUALITY_BOOST_BLOCK;
        $blocks[$block] = min(5, $blocks[$block] + COMPOUNDER_QUALITY_BOOST_POINTS);
    }

    return $blocks;
}

function extract_raw_value($node) {
    if (is_array($node) && array_key_exists('raw', $node)) {
        return $node['raw'];
    }
    return $node;
}

function flatten_quote_data(array $quote): array {
    $flat = [];
    foreach ($quote as $k => $v) {
        if (is_array($v)) {
            $flat[$k] = extract_raw_value($v);
        } else {
            $flat[$k] = $v;
        }
    }
    return $flat;
}

function fetch_json(string $url): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        throw new RuntimeException("Failed to fetch URL: {$url}");
    }
    $json = json_decode($content, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from URL: {$url}");
    }
    return $json;
}

function resolve_symbol(string $symbol): string {
    if (preg_match('/^[A-Z0-9\.\-\^=]{1,20}$/i', $symbol)) {
        return strtoupper($symbol);
    }

    $q = rawurlencode($symbol);
    $json = fetch_json("https://query1.finance.yahoo.com/v1/finance/search?q={$q}");
    $quotes = safe_get($json, 'quotes', []);
    if (is_array($quotes) && isset($quotes[0]['symbol'])) {
        return (string) $quotes[0]['symbol'];
    }
    return strtoupper($symbol);
}

function fetch_yahoo_info(string $symbol): array {
    $resolved = resolve_symbol($symbol);
    $modules = implode(',', ['price', 'summaryProfile', 'summaryDetail', 'financialData', 'defaultKeyStatistics']);
    $summary = fetch_json("https://query2.finance.yahoo.com/v10/finance/quoteSummary/" . rawurlencode($resolved) . "?modules={$modules}");
    $result = safe_get(safe_get($summary, 'quoteSummary', []), 'result', []);

    if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
        throw new RuntimeException('No quoteSummary data found');
    }

    $r = $result[0];
    $price = flatten_quote_data(safe_get($r, 'price', []));
    $profile = flatten_quote_data(safe_get($r, 'summaryProfile', []));
    $detail = flatten_quote_data(safe_get($r, 'summaryDetail', []));
    $financial = flatten_quote_data(safe_get($r, 'financialData', []));
    $stats = flatten_quote_data(safe_get($r, 'defaultKeyStatistics', []));

    return [
        'symbol' => $resolved,
        'shortName' => safe_get($price, 'shortName'),
        'longName' => safe_get($price, 'longName'),
        'quoteType' => safe_get($price, 'quoteType', 'EQUITY'),
        'sector' => safe_get($profile, 'sector'),
        'industry' => safe_get($profile, 'industry'),
        'returnOnAssets' => safe_get($financial, 'returnOnAssets'),
        'returnOnEquity' => safe_get($financial, 'returnOnEquity'),
        'priceToBook' => safe_get($stats, 'priceToBook'),
        'forwardPE' => safe_get($detail, 'forwardPE'),
        'trailingPE' => safe_get($detail, 'trailingPE'),
        'epsCurrentYear' => safe_get($stats, 'epsCurrentYear'),
        'epsForward' => safe_get($stats, 'forwardEps'),
        'beta' => safe_get($detail, 'beta'),
        'totalDebt' => safe_get($financial, 'totalDebt'),
        'totalCash' => safe_get($financial, 'totalCash'),
        'ebitda' => safe_get($financial, 'ebitda'),
        'debtToEquity' => safe_get($financial, 'debtToEquity'),
        'overallRisk' => safe_get($stats, 'overallRisk'),
        'dividendYield' => safe_get($detail, 'dividendYield'),
        'payoutRatio' => safe_get($detail, 'payoutRatio'),
        'dividendRate' => safe_get($detail, 'dividendRate'),
        'trailingAnnualDividendRate' => safe_get($detail, 'trailingAnnualDividendRate'),
        'trailingAnnualDividendYield' => safe_get($detail, 'trailingAnnualDividendYield'),
    ];
}

function analyze_stock(string $inputSymbol): array {
    $name = $inputSymbol;

    try {
        $info = fetch_yahoo_info($inputSymbol);
        $symbol = safe_get($info, 'symbol', $inputSymbol);
        $name = safe_get($info, 'shortName') ?: (safe_get($info, 'longName') ?: $inputSymbol);

        $quote_type = strtoupper((string) safe_get($info, 'quoteType', ''));
        if (!in_array($quote_type, ['EQUITY', 'ETF'], true)) {
            return [
                'Ticker' => $inputSymbol,
                'Name' => $name,
                'Error' => "Invalid quoteType: {$quote_type}",
                'Profil' => null,
                'Score' => null,
                'Flags' => null,
                'Details' => null,
            ];
        }

        $is_financial = is_financial_firm($info);
        $profile = $is_financial ? 'Financial' : 'Standard';

        if ($is_financial) {
            $blocks = [
                'Quality' => score_quality_financial($info),
                'Safety' => score_safety_financial($info),
                'ValueGrowth' => score_value_growth_financial($info),
                'Dividend' => score_dividend_financial($info),
            ];
            $raw = build_raw_financial($info);
        } else {
            $blocks = [
                'Quality' => score_quality_standard($info),
                'Safety' => score_safety_standard($info),
                'ValueGrowth' => score_value_growth_standard($info),
                'Dividend' => score_dividend_standard($info),
            ];
            $raw = build_raw_standard($info);
        }

        $flags = compute_flags($info, $profile, $raw);
        $blocks = apply_all_penalties($profile, $blocks, $flags, $raw);
        $blocks = apply_compounder_boost($profile, $blocks, $raw);

        $score_raw = array_sum($blocks);
        $score = normalize_score((int) $score_raw, $profile);
        $recommendation = get_recommendation($score, $flags);

        return [
            'Ticker' => $symbol,
            'Name' => $name,
            'Profil' => $profile,
            'Score' => (string) $score,
            'Recommendation' => $recommendation,
            'Flags' => $flags,
            'Details' => [
                'blocks' => $blocks,
                'raw' => $raw,
            ],
        ];
    } catch (Throwable $e) {
        return [
            'Ticker' => $inputSymbol,
            'Name' => $name,
            'Error' => $e->getMessage(),
            'Profil' => null,
            'Score' => null,
            'Flags' => null,
            'Details' => null,
        ];
    }
}

function analyze_portfolio(array $symbols, bool $isCli): array {
    $results = [];
    foreach ($symbols as $symbol) {
        if ($isCli) {
            fwrite(STDOUT, "Analyzing {$symbol}..." . PHP_EOL);
        }
        $results[] = analyze_stock($symbol);
    }
    return $results;
}

function export_csv(array $results, string $filepath): void {
    $fields = ['Ticker','Name','Profil','Score','Recommendation','Flags','yield_pct','payout_pct','pegy','pe_fwd','pe_ttm','pb','roe','roa','beta','debtToEquity','overallRisk'];
    $f = fopen($filepath, 'wb');
    if ($f === false) {
        return;
    }
    fputcsv($f, $fields);
    foreach ($results as $r) {
        $raw = safe_get(safe_get($r, 'Details', []), 'raw', []);
        $flags = safe_get($r, 'Flags', []) ?: [];
        $summary = [];
        foreach ($flags as $k => $v) {
            if ($k !== 'missing_fields' && $v) {
                $summary[] = $k;
            }
        }
        $reco = safe_get($r, 'Recommendation', []) ?: [];
        fputcsv($f, [
            safe_get($r, 'Ticker'),
            safe_get($r, 'Name'),
            safe_get($r, 'Profil'),
            safe_get($r, 'Score'),
            safe_get($reco, 'action', 'N/A'),
            $summary ? implode(', ', $summary) : 'None',
            safe_get($raw, 'yield_pct'), safe_get($raw, 'payout_pct'), safe_get($raw, 'pegy'), safe_get($raw, 'pe_fwd'), safe_get($raw, 'pe_ttm'),
            safe_get($raw, 'pb'), safe_get($raw, 'roe'), safe_get($raw, 'roa'), safe_get($raw, 'beta'), safe_get($raw, 'debtToEquity'), safe_get($raw, 'overallRisk'),
        ]);
    }
    fclose($f);
}

function parse_cli_arguments(array $argv): array {
    $args = array_slice($argv, 1);
    if (!$args) {
        fwrite(STDOUT, 'No arguments provided, using default portfolio (20 stocks)' . PHP_EOL);
        return DEFAULT_PORTFOLIO;
    }

    if (($args[0] ?? '') === '--file' && isset($args[1]) && count($args) === 2) {
        if (!is_file($args[1])) {
            fwrite(STDERR, "Error: File '{$args[1]}' not found" . PHP_EOL);
            exit(1);
        }
        $lines = file($args[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $symbols = array_values(array_filter(array_map('trim', $lines), fn ($x) => $x !== ''));
        fwrite(STDOUT, 'Loaded ' . count($symbols) . " symbols from {$args[1]}" . PHP_EOL);
        return $symbols;
    }

    if (in_array($args[0], ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage (CLI): php scorecard.php [--file symbols.txt | SYMBOL1 SYMBOL2 ...]\n");
        fwrite(STDOUT, "Usage (Web): /scorecard.php?symbols=AI.PA,ORA.PA\n");
        fwrite(STDOUT, "Options: --self-test\n");
        exit(0);
    }

    if (($args[0] ?? '') === '--self-test') {
        assert(abs(normalize_yield_fraction(4.37) - 0.0437) < 0.0001);
        assert(abs(normalize_yield_fraction(0.0437) - 0.0437) < 0.0001);
        assert(abs(normalize_payout_ratio(2.2059) - 2.2059) < 0.0001);
        fwrite(STDOUT, "Unit tests passed!" . PHP_EOL);
        exit(0);
    }

    fwrite(STDOUT, 'Analyzing ' . count($args) . " symbol(s) from command line" . PHP_EOL);
    return $args;
}

function run_cli(array $argv): int {
    $symbols = parse_cli_arguments($argv);
    $results = analyze_portfolio($symbols, true);

    $json_file = 'stock_scores_with_flags.json';
    file_put_contents($json_file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fwrite(STDOUT, PHP_EOL . "=== Results saved to {$json_file} ===" . PHP_EOL);

    $csv_file = 'invest_scorecard.csv';
    export_csv($results, $csv_file);
    fwrite(STDOUT, "CSV exported to {$csv_file}" . PHP_EOL);

    fwrite(STDOUT, PHP_EOL . "=== Summary ===" . PHP_EOL);
    foreach ($results as $r) {
        if (isset($r['Error'])) {
            fwrite(STDOUT, "{$r['Ticker']}: ERROR - {$r['Error']}" . PHP_EOL);
            continue;
        }

        $flags = [];
        foreach ($r['Flags'] as $k => $v) {
            if ($k !== 'missing_fields' && $v) {
                $flags[] = $k;
            }
        }
        $flags_str = $flags ? implode(', ', $flags) : 'None';
        $reco = safe_get($r, 'Recommendation', []) ?: [];
        $action = safe_get($reco, 'action', 'N/A');
        $warnings = safe_get($reco, 'warnings', []);
        $warnings_str = is_array($warnings) ? implode(' ', $warnings) : '';
        fwrite(STDOUT, "{$r['Ticker']}: {$r['Score']} ({$r['Profil']}) [{$action}] - Flags: {$flags_str} {$warnings_str}" . PHP_EOL);
    }

    return 0;
}

function run_web(): void {
    header('Content-Type: application/json; charset=utf-8');
    $symbols_param = isset($_GET['symbols']) ? trim((string) $_GET['symbols']) : '';

    if ($symbols_param === '') {
        http_response_code(200);
        echo json_encode([
            'message' => 'Provide symbols via query string',
            'example' => '/scorecard.php?symbols=AI.PA,ORA.PA',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return;
    }

    $symbols = array_values(array_filter(array_map('trim', explode(',', $symbols_param)), fn ($x) => $x !== ''));
    $results = analyze_portfolio($symbols, false);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

if (PHP_SAPI === 'cli') {
    exit(run_cli($argv));
}

run_web();
