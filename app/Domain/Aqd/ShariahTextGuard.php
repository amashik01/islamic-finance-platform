<?php

namespace App\Domain\Aqd;

/**
 * Server-side screen for free-text terms. It blocks wording that turns a partnership or sale into a guaranteed return or a
 * capital guarantee, which the cited sources treat as invalid (rules MUD-PROFIT-RATIO, MUD-NO-CAPITAL-PROFIT-GUARANTEE,
 * MUS-NO-CAPITAL-GUARANTEE, MUS-NO-PROFIT-GUARANTEE). It is a safety net, not a Shariah ruling: it cannot recognise every
 * possible phrasing, so the Shariah review remains the real control. Negated wording ("no guaranteed return") is allowed.
 */
final class ShariahTextGuard
{
    /** @var array<string, array{pattern: string, rule: string, message: string}> */
    private const PATTERNS = [
        'guaranteed_return' => ['pattern' => '/\b(guarantee[sd]?|assured|promised?|fixed|certain|risk[- ]free)\b[^.]{0,30}\b(returns?|profits?|yields?|dividends?)\b/i', 'rule' => 'MUD-NO-CAPITAL-PROFIT-GUARANTEE', 'message' => 'a guaranteed or fixed return'],
        'return_guaranteed' => ['pattern' => '/\b(returns?|profits?)\b[^.]{0,20}\b(is|are|will be|shall be)\s+(guaranteed|assured|fixed|certain)\b/i', 'rule' => 'MUD-NO-CAPITAL-PROFIT-GUARANTEE', 'message' => 'a guaranteed or fixed return'],
        'capital_guarantee' => ['pattern' => '/\b(guarantee[sd]?|assure[sd]?|protect(ed|s)?|insure[sd]?|underwrite[sn]?)\b[^.]{0,30}\b(capital|principal|investment|ras[- ]ul[- ]maal)\b/i', 'rule' => 'MUS-NO-CAPITAL-GUARANTEE', 'message' => 'a guarantee of capital'],
        'capital_guaranteed' => ['pattern' => '/\b(capital|principal|investment)\b[^.]{0,20}\b(is|are|will be|shall be)\s+(guaranteed|protected|assured|repaid in full|returned in full)\b/i', 'rule' => 'MUS-NO-CAPITAL-GUARANTEE', 'message' => 'a guarantee of capital'],
        'mudarib_covers_loss' => ['pattern' => '/\b(mudarib|manager|entrepreneur|business|partner)\b[^.]{0,40}\b(bears?|covers?|compensates?|reimburses?|makes? good|indemnif\w+)\b[^.]{0,30}\b(all |any |ordinary |every )?(losses|loss)\b/i', 'rule' => 'MUD-LOSS-RABB', 'message' => 'a promise that the working partner covers ordinary loss'],
        'buyback_face_value' => ['pattern' => '/\b(buy[- ]?back|repurchase|redeem)\b[^.]{0,40}\b(face value|nominal value|pre[- ]?agreed (price|value)|original (price|capital))\b/i', 'rule' => 'MUS-NO-FACE-VALUE-BUYBACK', 'message' => 'a buy-back at face or pre-agreed value'],
        'interest' => ['pattern' => '/\b(interest rate|riba|penalty interest|late (payment )?(fee|charge)s? (of|at) \d)/i', 'rule' => 'MUR-NO-RECEIVABLE-BEFORE-SALE', 'message' => 'interest or a late-payment charge'],
    ];

    /** Denials such as "no guaranteed return" are allowed. */
    private const NEGATION = '/\b(no|not|never|without|neither|nor|cannot|can not|does not|do not|is not|are not|nothing)\b[^.]{0,25}$/i';

    private const FAULT = '/negligen|misconduct|breach|fault|violat|fraud|unauthori[sz]ed|wilful|willful/i';

    private static function sentence(string $text, int $offset): string
    {
        $start = strrpos(substr($text, 0, $offset), '.');
        $end = strpos($text, '.', $offset);

        return substr($text, $start === false ? 0 : $start + 1, ($end === false ? strlen($text) : $end) - ($start === false ? 0 : $start + 1));
    }

    /** @return list<array{rule: string, message: string}> */
    public static function violations(string $text): array
    {
        $out = [];
        foreach (self::PATTERNS as $name => $p) {
            if (preg_match_all($p['pattern'], $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    $before = substr($text, max(0, $offset - 35), min(35, $offset));
                    if (preg_match(self::NEGATION, $before)) {
                        continue;
                    }
                    // Liability for fault, breach, misconduct or negligence is permitted (MUD-MUDARIB-FAULT): only ordinary loss is protected.
                    if ($name === 'mudarib_covers_loss' && preg_match(self::FAULT, self::sentence($text, $offset))) {
                        continue;
                    }
                    $out[] = ['rule' => $p['rule'], 'message' => $p['message']];
                    break;
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $terms @return list<string> human messages naming the field and the rule */
    public static function scan(array $terms, array $labels = []): array
    {
        $msgs = [];
        $walk = function ($value, string $key) use (&$walk, &$msgs, $labels) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, $key);
                }

                return;
            }
            if (! is_string($value) || trim($value) === '') {
                return;
            }
            foreach (self::violations($value) as $v) {
                $msgs[] = ($labels[$key] ?? $key).' contains '.$v['message'].' (rule '.$v['rule'].'). Remove it: this contract shares actual results and guarantees nothing.';
            }
        };
        foreach ($terms as $k => $v) {
            $walk($v, (string) $k);
        }

        return array_values(array_unique($msgs));
    }
}
