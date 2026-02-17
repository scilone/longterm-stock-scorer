#!/usr/bin/env python3
"""
Stock Scoring Script with Anomaly Flags
Scores stocks using a Buffett/Lynch/Dividend philosophy with long-term stability focus.
Includes automatic anomaly detection (missing data, unsustainable payout, etc.)

Usage:
    python stock_scoring_with_flags.py ISIN1 ISIN2 ISIN3 ...
    python stock_scoring_with_flags.py --file symbols.txt
    python stock_scoring_with_flags.py  # utilise la liste par défaut
"""

import yfinance as yf
import json
import csv
import sys
from typing import Dict, Any, List, Optional, Tuple

# =============================================================================
# CONFIGURATION - Ajuste ces seuils selon ta tolérance
# =============================================================================

SCORING_NORMALIZATION_ENABLED = True
VERBOSE = False

HIGH_YIELD_DIVIDEND_PENALTY_ENABLED = True
HIGH_YIELD_DIVIDEND_PENALTY_POINTS = 1

FINANCIAL_OVERALLRISK_SAFETY_PENALTY_ENABLED = True
FINANCIAL_OVERALLRISK_SAFETY_THRESHOLDS = [(7, 1)]

EXTREME_PAYOUT_GATE_ENABLED = True
EXTREME_PAYOUT_THRESHOLDS = [
    (150, {"dividend_to": 0, "safety_minus": 1}),
    (100, {"dividend_cap": 1})
]

COMPOUNDER_QUALITY_BOOST_ENABLED = True
COMPOUNDER_QUALITY_BOOST_BLOCK = "Quality"
COMPOUNDER_QUALITY_BOOST_POINTS = 1

COMPOUNDER_QUALITY_CRITERIA = {
    "roa_min": 0.065,
    "beta_max": 0.9,
    "payout_min": 30.0,
    "payout_max": 70.0,
    "safety_min": 3
}

SPECIAL_DIVIDEND_DETECTION_ENABLED = True
SPECIAL_DIVIDEND_THRESHOLD_PCT = 20.0

SPECIAL_DIVIDEND_PENALTY = {
    "max_dividend_score": 2,
    "flag_special_dividend": True
}

RECOMMENDATION_ENABLED = True

RECOMMENDATION_THRESHOLDS = {
    "ACHETER": {
        "score_min": 16,
        "score_max": 20,
        "description": "Excellence exceptionnelle - Opportunité d'accumulation prioritaire",
        "color": "green"
    },
    "RENFORCER": {
        "score_min": 14,
        "score_max": 15,
        "description": "Très bonne qualité - Accumuler progressivement sur replis",
        "color": "lightgreen"
    },
    "CONSERVER": {
        "score_min": 12,
        "score_max": 13,
        "description": "Cœur de portefeuille - Tenir long terme, ne pas vendre",
        "color": "gray"
    },
    "ALLEGER": {
        "score_min": 9,
        "score_max": 11,
        "description": "Qualité correcte mais pas prioritaire - Réduire si surpondéré",
        "color": "orange"
    },
    "VENDRE": {
        "score_min": 0,
        "score_max": 8,
        "description": "Qualité insuffisante ou risque élevé - Sortir progressivement",
        "color": "red"
    }
}

# Seuils anomalies
PAYOUT_UNSUSTAINABLE_MIN = 0.95   # Flag si payout >= 95% (0.95)
HIGH_YIELD_ALERT_MIN     = 0.08   # Flag si yield >= 8%
EXTREME_PEG_LOW_MAX      = 0.20   # Flag si PEGY <= 0.20 (trop beau pour être vrai)
EXTREME_PEG_HIGH_MIN     = 3.00   # Flag si PEGY >= 3.0 (overvalued)
SPECIAL_DIV_MULTIPLIER   = 1.25   # Flag si trailing yield > 1.25x forward yield
MISSING_DATA_MIN_FIELDS  = 3      # Flag si >= 3 champs clés manquants

# Seuils scoring (inchangés depuis ton dernier run)
QUALITY_ROA_THRESHOLDS = [0.02, 0.05]
QUALITY_ROE_THRESHOLDS = [0.08, 0.12, 0.15]
SAFETY_BETA_THRESHOLDS = [0.7, 1.2]
SAFETY_DEBT_RATIO_THRESHOLD = 2.0
PEGY_THRESHOLDS = [0.5, 1.0]
PE_FWD_THRESHOLD = 15
DIVIDEND_YIELD_THRESHOLDS = [0.02, 0.04, 0.06]
DIVIDEND_PAYOUT_MAX = 0.8


# =============================================================================
# HELPERS
# =============================================================================

def _safe_get(d: Any, key: str, default=None):
    """Récupère d[key] ou default si d n'est pas dict ou key absent."""
    if not isinstance(d, dict):
        return default
    return d.get(key, default)

def _is_missing(x):
    if x is None:
        return True
    return False


def normalize_yield_fraction(y):
    if y is None:
        return None
    if y > 1.0:
        return y / 100.0
    return y


def normalize_payout_ratio(p):
    if p is None:
        return None
    if p >= 0:
        return p
    return None


def detect_special_dividend(yield_pct) -> bool:
    if not SPECIAL_DIVIDEND_DETECTION_ENABLED:
        return False
    if yield_pct is None:
        return False
    return yield_pct > SPECIAL_DIVIDEND_THRESHOLD_PCT


def get_recommendation(score: int, flags: dict) -> Optional[dict]:
    if not RECOMMENDATION_ENABLED:
        return None

    effective_score = score

    if flags.get("special_dividend") and score >= 12:
        effective_score = score - 1

    action = None
    description = None

    for reco_action, config in RECOMMENDATION_THRESHOLDS.items():
        if config["score_min"] <= effective_score <= config["score_max"]:
            action = reco_action
            description = config["description"]
            break

    if action is None:
        return {"action": "INDEFINI", "description": "Score hors limites"}

    warnings = []

    if flags.get("payout_unsustainable") and score >= 14:
        warnings.append("⚠️ Surveiller dividende (payout élevé)")

    if flags.get("high_yield_alert") and action in ["ACHETER", "RENFORCER"]:
        warnings.append("⚠️ Yield extrême, vérifier durabilité")

    if flags.get("special_dividend") and score >= 12:
        warnings.append("⚠️ Dividende exceptionnel détecté")

    result = {
        "action": action,
        "description": description
    }

    if warnings:
        result["warnings"] = warnings

    return result


def _run_unit_tests():
    assert abs(normalize_yield_fraction(4.37) - 0.0437) < 0.0001
    assert abs(normalize_yield_fraction(0.0437) - 0.0437) < 0.0001
    assert abs(normalize_payout_ratio(2.2059) - 2.2059) < 0.0001
    payout_pct = normalize_payout_ratio(2.2059) * 100
    assert abs(payout_pct - 220.59) < 0.01
    print("Unit tests passed!")


def get_profile_max_total(profile: str) -> int:
    if profile == "Financial":
        return 20
    return 17


def normalize_score(score_raw: int, profile: str) -> int:
    if not SCORING_NORMALIZATION_ENABLED:
        return score_raw
    max_total = get_profile_max_total(profile)
    score_normalized = round(score_raw * 20 / max_total)
    return max(0, min(20, score_normalized))


def apply_all_penalties(profile: str, blocks: dict, flags: dict, raw: dict) -> dict:
    if flags.get("special_dividend"):
        if SPECIAL_DIVIDEND_DETECTION_ENABLED and "Dividend" in blocks:
            max_div = SPECIAL_DIVIDEND_PENALTY.get("max_dividend_score", 2)
            blocks["Dividend"] = min(blocks["Dividend"], max_div)

    if flags.get("high_yield_alert"):
        if HIGH_YIELD_DIVIDEND_PENALTY_ENABLED and "Dividend" in blocks:
            blocks["Dividend"] = max(0, blocks["Dividend"] - HIGH_YIELD_DIVIDEND_PENALTY_POINTS)

    if profile == "Financial" and FINANCIAL_OVERALLRISK_SAFETY_PENALTY_ENABLED:
        overall_risk = _safe_get(raw, "overallRisk")
        if overall_risk is not None:
            for threshold, penalty in sorted(FINANCIAL_OVERALLRISK_SAFETY_THRESHOLDS, reverse=True):
                if overall_risk >= threshold:
                    blocks["Safety"] = max(0, blocks["Safety"] - penalty)
                    break

    if profile == "Standard" and EXTREME_PAYOUT_GATE_ENABLED:
        payout_pct = _safe_get(raw, "payout_pct")
        if payout_pct is not None:
            for threshold, action in sorted(EXTREME_PAYOUT_THRESHOLDS, key=lambda x: x[0], reverse=True):
                if payout_pct >= threshold:
                    if "dividend_to" in action:
                        blocks["Dividend"] = action["dividend_to"]
                    if "dividend_cap" in action:
                        blocks["Dividend"] = min(blocks["Dividend"], action["dividend_cap"])
                    if "safety_minus" in action:
                        blocks["Safety"] = max(0, blocks["Safety"] - action["safety_minus"])
                    break

    return blocks


def apply_compounder_boost(profile: str, blocks: dict, raw: dict) -> dict:
    if not COMPOUNDER_QUALITY_BOOST_ENABLED:
        return blocks
    if profile != "Standard":
        return blocks

    roa = _safe_get(raw, "roa")
    beta = _safe_get(raw, "beta")
    payout = _safe_get(raw, "payout_pct")
    safety = blocks.get("Safety", 0)

    if roa is None or beta is None or payout is None:
        return blocks

    criteria = COMPOUNDER_QUALITY_CRITERIA
    if (roa >= criteria["roa_min"] and
        beta < criteria["beta_max"] and
        criteria["payout_min"] <= payout <= criteria["payout_max"] and
        safety >= criteria["safety_min"]):

        block_name = COMPOUNDER_QUALITY_BOOST_BLOCK
        blocks[block_name] = min(5, blocks[block_name] + COMPOUNDER_QUALITY_BOOST_POINTS)

    return blocks



# =============================================================================
# DETECTION DE PROFIL (Financial vs Standard)
# =============================================================================

def is_financial_firm(info: dict) -> bool:
    """Détecte si l'entreprise est de type Financial (banque, assurance)."""
    sector = _safe_get(info, "sector", "").lower()
    industry = _safe_get(info, "industry", "").lower()

    financial_keywords = [
        "financial", "bank", "insurance", "capital markets",
        "asset management", "credit services"
    ]

    for kw in financial_keywords:
        if kw in sector or kw in industry:
            return True
    return False


# =============================================================================
# SCORING - STANDARD PROFILE
# =============================================================================

def score_quality_standard(info: dict) -> int:
    """Score Quality [0..2] pour profil Standard."""
    roa = _safe_get(info, "returnOnAssets")
    if roa is None:
        return 1  # défaut prudent

    if roa >= QUALITY_ROA_THRESHOLDS[1]:
        return 2
    elif roa >= QUALITY_ROA_THRESHOLDS[0]:
        return 1
    else:
        return 0


def score_safety_standard(info: dict) -> int:
    """Score Safety [0..5] pour profil Standard."""
    score = 0

    # Beta (0..2)
    beta = _safe_get(info, "beta")
    if beta is not None:
        if beta < SAFETY_BETA_THRESHOLDS[0]:
            score += 2
        elif beta < SAFETY_BETA_THRESHOLDS[1]:
            score += 1
    else:
        score += 1  # défaut neutre

    # Debt ratio (0..3)
    total_debt = _safe_get(info, "totalDebt")
    ebitda = _safe_get(info, "ebitda")

    if total_debt is not None and ebitda is not None and ebitda > 0:
        debt_ratio = total_debt / ebitda
        if debt_ratio < SAFETY_DEBT_RATIO_THRESHOLD:
            score += 3
        elif debt_ratio < SAFETY_DEBT_RATIO_THRESHOLD * 1.5:
            score += 2
        else:
            score += 1
    else:
        score += 1  # défaut prudent

    return min(score, 5)


def score_value_growth_standard(info: dict) -> int:
    """Score ValueGrowth [0..5] pour profil Standard."""
    pegy = compute_pegy_standard(info)
    pe_fwd = _safe_get(info, "forwardPE")

    score = 0

    # PEGY (0..3)
    if pegy is not None:
        if pegy <= PEGY_THRESHOLDS[0]:
            score += 3
        elif pegy <= PEGY_THRESHOLDS[1]:
            score += 2
        else:
            score += 1
    else:
        score += 1  # défaut

    # PE forward (0..2)
    if pe_fwd is not None:
        if pe_fwd < PE_FWD_THRESHOLD:
            score += 2
        elif pe_fwd < PE_FWD_THRESHOLD * 1.5:
            score += 1
    else:
        score += 1  # défaut

    return min(score, 5)


def score_dividend_standard(info: dict) -> int:
    raw_yield = _safe_get(info, "dividendYield")
    raw_payout = _safe_get(info, "payoutRatio")
    div_yield = normalize_yield_fraction(raw_yield)
    payout_ratio = normalize_payout_ratio(raw_payout)

    score = 0

    if div_yield is not None:
        if div_yield >= DIVIDEND_YIELD_THRESHOLDS[2]:
            score += 3
        elif div_yield >= DIVIDEND_YIELD_THRESHOLDS[1]:
            score += 2
        elif div_yield >= DIVIDEND_YIELD_THRESHOLDS[0]:
            score += 1

    if payout_ratio is not None:
        if payout_ratio <= DIVIDEND_PAYOUT_MAX:
            score += 2
        elif payout_ratio <= 1.0:
            score += 1
    else:
        score += 1

    return min(score, 5)


def compute_pegy_standard(info: dict) -> Optional[float]:
    """Calcule PEGY = (P/E forward) / (croissance EPS % estimée)."""
    pe_fwd = _safe_get(info, "forwardPE")
    eps_current = _safe_get(info, "epsCurrentYear")
    eps_forward = _safe_get(info, "epsForward")

    if not (pe_fwd and eps_current and eps_forward and eps_current > 0):
        return None

    growth_rate = (eps_forward - eps_current) / eps_current
    if growth_rate <= 0:
        return None

    return pe_fwd / (growth_rate * 100)


def get_standard_score(info: dict) -> Tuple[int, dict]:
    """Calcule le score total [0..20] et les blocks pour profil Standard."""
    blocks = {
        "Quality": score_quality_standard(info),
        "Safety": score_safety_standard(info),
        "ValueGrowth": score_value_growth_standard(info),
        "Dividend": score_dividend_standard(info)
    }
    total = sum(blocks.values())
    return total, blocks


# =============================================================================
# SCORING - FINANCIAL PROFILE
# =============================================================================

def score_quality_financial(info: dict) -> int:
    """Score Quality [0..5] pour profil Financial."""
    roe = _safe_get(info, "returnOnEquity")
    pb = _safe_get(info, "priceToBook")

    score = 0

    # ROE (0..3)
    if roe is not None:
        if roe >= QUALITY_ROE_THRESHOLDS[2]:
            score += 3
        elif roe >= QUALITY_ROE_THRESHOLDS[1]:
            score += 2
        elif roe >= QUALITY_ROE_THRESHOLDS[0]:
            score += 1
    else:
        score += 1  # défaut

    # P/B (0..2)
    if pb is not None:
        if pb < 1.0:
            score += 2
        elif pb < 1.5:
            score += 1
    else:
        score += 1  # défaut

    return min(score, 5)


def score_safety_financial(info: dict) -> int:
    """Score Safety [0..5] pour profil Financial."""
    beta = _safe_get(info, "beta")
    debt_to_equity = _safe_get(info, "debtToEquity")
    overall_risk = _safe_get(info, "overallRisk")

    score = 0

    # Beta (0..2)
    if beta is not None:
        if beta < SAFETY_BETA_THRESHOLDS[0]:
            score += 2
        elif beta < SAFETY_BETA_THRESHOLDS[1]:
            score += 1
    else:
        score += 1

    # Debt/Equity (0..2)
    if debt_to_equity is not None:
        if debt_to_equity < 100:
            score += 2
        elif debt_to_equity < 150:
            score += 1
    else:
        score += 1

    # overallRisk (0..1)
    if overall_risk is not None:
        if overall_risk <= 2:
            score += 1

    return min(score, 5)


def score_value_growth_financial(info: dict) -> int:
    """Score ValueGrowth [0..5] pour profil Financial."""
    pe_fwd = _safe_get(info, "forwardPE")
    pe_ttm = _safe_get(info, "trailingPE")
    pb = _safe_get(info, "priceToBook")

    score = 0

    # PE forward (0..2)
    if pe_fwd is not None:
        if pe_fwd < 10:
            score += 2
        elif pe_fwd < 12:
            score += 1
    else:
        score += 1

    # PE trailing (0..2)
    if pe_ttm is not None:
        if pe_ttm < 12:
            score += 2
        elif pe_ttm < 15:
            score += 1
    else:
        score += 1

    # P/B bonus (0..1)
    if pb is not None and pb < 0.8:
        score += 1

    return min(score, 5)


def score_dividend_financial(info: dict) -> int:
    raw_yield = _safe_get(info, "dividendYield")
    raw_payout = _safe_get(info, "payoutRatio")
    div_yield = normalize_yield_fraction(raw_yield)
    payout_ratio = normalize_payout_ratio(raw_payout)

    score = 0

    if div_yield is not None:
        if div_yield >= 0.05:
            score += 3
        elif div_yield >= 0.03:
            score += 2
        elif div_yield >= 0.02:
            score += 1

    if payout_ratio is not None:
        if payout_ratio <= 0.7:
            score += 2
        elif payout_ratio <= 0.9:
            score += 1
    else:
        score += 1

    return min(score, 5)


def get_financial_score(info: dict) -> Tuple[int, dict]:
    """Calcule le score total [0..20] et les blocks pour profil Financial."""
    blocks = {
        "Quality": score_quality_financial(info),
        "Safety": score_safety_financial(info),
        "ValueGrowth": score_value_growth_financial(info),
        "Dividend": score_dividend_financial(info)
    }
    total = sum(blocks.values())
    return total, blocks


# =============================================================================
# BUILD RAW METRICS (pour Details.raw dans le JSON)
# =============================================================================

def build_raw_standard(info: dict) -> dict:
    raw_yield = _safe_get(info, "dividendYield")
    raw_payout = _safe_get(info, "payoutRatio")
    div_yield = normalize_yield_fraction(raw_yield)
    payout_ratio = normalize_payout_ratio(raw_payout)

    return {
        "yield_pct": round(div_yield * 100, 2) if div_yield else None,
        "payout_pct": round(payout_ratio * 100, 2) if payout_ratio else None,
        "pegy": compute_pegy_standard(info),
        "roa": _safe_get(info, "returnOnAssets"),
        "totalDebt": _safe_get(info, "totalDebt"),
        "totalCash": _safe_get(info, "totalCash"),
        "ebitda": _safe_get(info, "ebitda"),
        "beta": _safe_get(info, "beta")
    }


def build_raw_financial(info: dict) -> dict:
    raw_yield = _safe_get(info, "dividendYield")
    raw_payout = _safe_get(info, "payoutRatio")
    div_yield = normalize_yield_fraction(raw_yield)
    payout_ratio = normalize_payout_ratio(raw_payout)

    return {
        "roe": _safe_get(info, "returnOnEquity"),
        "pb": _safe_get(info, "priceToBook"),
        "pe_fwd": _safe_get(info, "forwardPE"),
        "pe_ttm": _safe_get(info, "trailingPE"),
        "beta": _safe_get(info, "beta"),
        "debtToEquity": _safe_get(info, "debtToEquity"),
        "overallRisk": _safe_get(info, "overallRisk"),
        "yield_pct": round(div_yield * 100, 2) if div_yield else None,
        "payout_pct": round(payout_ratio * 100, 2) if payout_ratio else None
    }


# =============================================================================
# ANOMALY FLAGS
# =============================================================================

def compute_flags(info: dict, profile: str, raw: dict) -> dict:
    """
    Détecte les anomalies automatiques à partir de info + raw.
    Retourne un dict de flags booléens + liste missing_fields.
    """
    flags = {
        "missing_data": False,
        "missing_fields": [],
        "payout_unsustainable": False,
        "special_dividend": False,
        "extreme_peg": False,
        "high_yield_alert": False,
    }

    # --- 1) missing_data ---
    if profile == "Financial":
        required = ["roe", "pb", "pe_fwd", "pe_ttm", "beta", "overallRisk", "yield_pct", "payout_pct"]
    else:
        required = ["roa", "totalDebt", "totalCash", "ebitda", "beta", "yield_pct", "payout_pct", "pegy"]

    missing = [k for k in required if _is_missing(_safe_get(raw, k))]
    flags["missing_fields"] = missing
    flags["missing_data"] = (len(missing) >= MISSING_DATA_MIN_FIELDS)

    # --- 2) payout_unsustainable ---
    payout_pct = _safe_get(raw, "payout_pct")
    if payout_pct is not None:
        flags["payout_unsustainable"] = (payout_pct >= PAYOUT_UNSUSTAINABLE_MIN * 100)

    # --- 3) high_yield_alert ---
    y = _safe_get(raw, "yield_pct")
    if y is not None:
        flags["high_yield_alert"] = (y >= HIGH_YIELD_ALERT_MIN * 100)

    # --- 3b) special_dividend via yield anormalement élevé ---
    if detect_special_dividend(y):
        flags["special_dividend"] = True

    # --- 4) extreme_peg (seulement Standard) ---
    if profile == "Standard":
        pegy = _safe_get(raw, "pegy")
        if pegy is not None:
            flags["extreme_peg"] = (pegy <= EXTREME_PEG_LOW_MAX) or (pegy >= EXTREME_PEG_HIGH_MIN)

    # --- 5) special_dividend (heuristique Yahoo) ---
    dividend_rate = _safe_get(info, "dividendRate")
    trailing_div_rate = _safe_get(info, "trailingAnnualDividendRate")
    raw_dividend_yield = _safe_get(info, "dividendYield")
    raw_trailing_div_yield = _safe_get(info, "trailingAnnualDividendYield")

    dividend_yield = normalize_yield_fraction(raw_dividend_yield)
    trailing_div_yield = normalize_yield_fraction(raw_trailing_div_yield)

    if dividend_rate and trailing_div_rate:
        if trailing_div_rate > dividend_rate * SPECIAL_DIV_MULTIPLIER:
            flags["special_dividend"] = True

    if dividend_yield and trailing_div_yield:
        if trailing_div_yield > dividend_yield * SPECIAL_DIV_MULTIPLIER:
            flags["special_dividend"] = True

    if flags["high_yield_alert"] and payout_pct is None:
        flags["special_dividend"] = True

    return flags


# =============================================================================
# MAIN SCORING FUNCTION
# =============================================================================

def analyze_stock(symbol: str) -> dict:
    """
    Analyse une action (ISIN ou ticker) et retourne un dict avec:
    - Ticker
    - Profil (Standard/Financial)
    - Score (/20)
    - Flags (anomalies)
    - Details (blocks + raw metrics)
    """
    company_name = symbol
    try:
        ticker = yf.Ticker(symbol)
        info = ticker.info
        company_name = _safe_get(info, "shortName") or _safe_get(info, "longName") or symbol

        # Vérif quote type
        quote_type = _safe_get(info, "quoteType", "")
        if quote_type.upper() not in ["EQUITY", "ETF"]:
            return {
                "Ticker": symbol,
                "Name": company_name,
                "Error": f"Invalid quoteType: {quote_type}",
                "Profil": None,
                "Score": None,
                "Flags": None,
                "Details": None
            }

        # Détection profil
        is_financial = is_financial_firm(info)
        profile = "Financial" if is_financial else "Standard"

        # Score et raw metrics
        if is_financial:
            _, blocks = get_financial_score(info)
            raw = build_raw_financial(info)
        else:
            _, blocks = get_standard_score(info)
            raw = build_raw_standard(info)

        # Flags (calculés AVANT les pénalités)
        flags = compute_flags(info, profile, raw)

        # Pénalités appliquées sur les blocks (100% visible)
        blocks = apply_all_penalties(profile, blocks, flags, raw)

        # Boost compounders de qualité (Standard uniquement)
        blocks = apply_compounder_boost(profile, blocks, raw)

        # Score = sum(blocks) normalisé /20
        score_raw = sum(blocks.values())
        score_normalized = normalize_score(score_raw, profile)

        if VERBOSE:
            max_total = get_profile_max_total(profile)
            expected = round(score_raw * 20 / max_total) if SCORING_NORMALIZATION_ENABLED else score_raw
            print(f"  {symbol}: blocks={blocks} sum={score_raw} -> normalized={score_normalized}/20")
            assert expected == score_normalized, f"Score mismatch: {expected} != {score_normalized}"

        recommendation = get_recommendation(score_normalized, flags)

        return {
            "Ticker": symbol,
            "Name": company_name,
            "Profil": profile,
            "Score": f"{score_normalized}",
            "Recommendation": recommendation,
            "Flags": flags,
            "Details": {
                "blocks": blocks,
                "raw": raw
            }
        }

    except Exception as e:
        return {
            "Ticker": symbol,
            "Name": company_name,
            "Error": str(e),
            "Profil": None,
            "Score": None,
            "Flags": None,
            "Details": None
        }


# =============================================================================
# BATCH ANALYSIS
# =============================================================================

def analyze_portfolio(symbols: List[str]) -> List[dict]:
    results = []
    for symbol in symbols:
        print(f"Analyzing {symbol}...")
        result = analyze_stock(symbol)
        results.append(result)
    return results


def summarize_results(results: List[dict]) -> None:
    valid_results = [r for r in results if r.get("Score") is not None and "Error" not in r]

    by_profile = {"Standard": [], "Financial": []}
    for r in valid_results:
        profile = r.get("Profil")
        if profile in by_profile:
            by_profile[profile].append(r)

    print("\n" + "=" * 60)
    print("SUMMARIZE RESULTS")
    print("=" * 60)

    for profile, items in by_profile.items():
        if not items:
            continue
        scores = [int(r["Score"]) for r in items]
        count = len(scores)
        mean_score = sum(scores) / count
        min_score = min(scores)
        max_score = max(scores)
        print(f"\n{profile}: count={count}, mean={mean_score:.1f}, min={min_score}, max={max_score}")

    all_sorted = sorted(valid_results, key=lambda r: int(r["Score"]), reverse=True)

    print("\n--- TOP 5 ---")
    for r in all_sorted[:5]:
        print(f"  {r['Score']}/20 | {r['Name'][:30]:30} | {r['Profil']}")

    print("\n--- BOTTOM 5 ---")
    for r in all_sorted[-5:]:
        print(f"  {r['Score']}/20 | {r['Name'][:30]:30} | {r['Profil']}")

    print("=" * 60)


def export_csv(results: List[dict], filepath: str) -> None:
    fields = [
        "Ticker",
        "Name",
        "Profil",
        "Score",
        "Recommendation",
        "Flags",
        "yield_pct",
        "payout_pct",
        "pegy",
        "pe_fwd",
        "pe_ttm",
        "pb",
        "roe",
        "roa",
        "beta",
        "debtToEquity",
        "overallRisk",
    ]
    with open(filepath, "w", newline="", encoding="utf-8") as csvfile:
        writer = csv.DictWriter(csvfile, fieldnames=fields)
        writer.writeheader()
        for r in results:
            raw = _safe_get(_safe_get(r, "Details", {}), "raw", {})
            flags = _safe_get(r, "Flags", {}) or {}
            flags_summary = [k for k, v in flags.items() if v and k != "missing_fields"]
            reco = _safe_get(r, "Recommendation", {}) or {}
            reco_action = reco.get("action", "N/A")
            writer.writerow({
                "Ticker": _safe_get(r, "Ticker"),
                "Name": _safe_get(r, "Name"),
                "Profil": _safe_get(r, "Profil"),
                "Score": _safe_get(r, "Score"),
                "Recommendation": reco_action,
                "Flags": ", ".join(flags_summary) if flags_summary else "None",
                "yield_pct": _safe_get(raw, "yield_pct"),
                "payout_pct": _safe_get(raw, "payout_pct"),
                "pegy": _safe_get(raw, "pegy"),
                "pe_fwd": _safe_get(raw, "pe_fwd"),
                "pe_ttm": _safe_get(raw, "pe_ttm"),
                "pb": _safe_get(raw, "pb"),
                "roe": _safe_get(raw, "roe"),
                "roa": _safe_get(raw, "roa"),
                "beta": _safe_get(raw, "beta"),
                "debtToEquity": _safe_get(raw, "debtToEquity"),
                "overallRisk": _safe_get(raw, "overallRisk"),
            })


# =============================================================================
# CLI ARGUMENT PARSING
# =============================================================================

def parse_arguments() -> List[str]:
    """Parse les arguments CLI et retourne la liste des symboles."""

    # Portefeuille par défaut (si aucun argument)
    DEFAULT_PORTFOLIO = [
        "FR0000120271",  # TotalEnergies
        "FR0000120503",  # Bouygues
        "FR0000125486",  # Vinci
        "FR0000133308",  # Orange
        "FR0010208488",  # Engie
        "FR0000120578",  # Sanofi
        "FR0000121261",  # Michelin
        "FR0000130577",  # Publicis
        "FR0000124141",  # Veolia
        "NL0000235190",  # Airbus
        "FR0000131104",  # BNP Paribas
        "FR0000045072",  # Crédit Agricole
        "FR0000130809",  # Société Générale
        "FR0000120628",  # AXA
        "NL0011623188",  # NN Group
        "IT0000072618",  # Intesa Sanpaolo
        "NL0011821202",  # ING Group
        "NL0011540547",  # ABN AMRO
        "DE0005140008",  # Deutsche Bank
        "ES0140609019",  # CaixaBank
    ]

    args = sys.argv[1:]

    # Aucun argument → portefeuille par défaut
    if not args:
        print("No arguments provided, using default portfolio (20 stocks)")
        return DEFAULT_PORTFOLIO

    # --file symbols.txt
    if args[0] == "--file" and len(args) == 2:
        filepath = args[1]
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                symbols = [line.strip() for line in f if line.strip()]
            print(f"Loaded {len(symbols)} symbols from {filepath}")
            return symbols
        except FileNotFoundError:
            print(f"Error: File '{filepath}' not found")
            sys.exit(1)

    # --help
    if args[0] in ["-h", "--help"]:
        print(__doc__)
        sys.exit(0)

    # Liste de symboles en arguments directs
    print(f"Analyzing {len(args)} symbol(s) from command line")
    return args


# =============================================================================
# MAIN
# =============================================================================

if __name__ == "__main__":
    # Parse arguments
    portfolio = parse_arguments()

    # Analyse
    results = analyze_portfolio(portfolio)

    # Sauvegarde JSON
    output_file = "stock_scores_with_flags.json"
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2, ensure_ascii=False)

    print(f"\n=== Results saved to {output_file} ===")

    # Export CSV
    csv_output_file = "invest_scorecard.csv"
    export_csv(results, csv_output_file)
    print(f"CSV exported to {csv_output_file}")

    # Affiche résumé
    print("\n=== Summary ===")
    for r in results:
        if "Error" in r:
            print(f"{r['Ticker']}: ERROR - {r['Error']}")
        else:
            flags_summary = [k for k, v in r["Flags"].items() if v and k != "missing_fields"]
            flags_str = ", ".join(flags_summary) if flags_summary else "None"
            reco = r.get("Recommendation", {})
            reco_action = reco.get("action", "N/A") if reco else "N/A"
            warnings = reco.get("warnings", []) if reco else []
            warnings_str = " ".join(warnings) if warnings else ""
            print(f"{r['Ticker']}: {r['Score']} ({r['Profil']}) [{reco_action}] - Flags: {flags_str} {warnings_str}")

    summarize_results(results)

