<?php

namespace App\Support;

use App\Enums\LedgerAccountType;
use App\Enums\TransactionType;

/**
 * What every ledger account and transaction type MEANS. A balanced entry is not evidence that the economics are right,
 * so each type must state the business event, its Shariah meaning, the accounts, whose money moves, the evidence and the
 * allowed lifecycle. A test fails when a case is added without an entry. See docs/shariah/LEDGER_SEMANTICS.md.
 * Nothing here is a Shariah ruling; the Shariah meaning column is the platform's stated intent, under review.
 */
final class LedgerSemantics
{
    /** @return array<string, array{meaning: string, owner: string, increases: string, decreases: string}> */
    public static function accounts(): array
    {
        return [
            'PLATFORM_CASH' => ['meaning' => 'The platform\'s OWN funds (Murabaha purchases and buyer payments). Never client money.', 'owner' => 'Platform', 'increases' => 'Murabaha buyer payment received', 'decreases' => 'Murabaha asset purchase'],
            'CUSTODY_CASH' => ['meaning' => 'Cash held in custody for clients and partners. Not the platform\'s money; never negative.', 'owner' => 'Clients and partners', 'increases' => 'Verified deposit, business capital received, business remittance, recovery received', 'decreases' => 'Withdrawal paid, capital deployed to the venture'],
            'INVESTOR_AVAILABLE' => ['meaning' => 'Claim of an investor to withdraw custody cash.', 'owner' => 'Investor', 'increases' => 'Deposit, capital/profit/recovery paid out', 'decreases' => 'Investment, withdrawal request'],
            'INVESTOR_PENDING' => ['meaning' => 'Withdrawal in progress.', 'owner' => 'Investor', 'increases' => 'Withdrawal requested', 'decreases' => 'Withdrawal paid or released'],
            'INVESTOR_INVESTED' => ['meaning' => 'Capital at risk committed to a venture (an investment interest). Not a guaranteed claim and not a debt of the business.', 'owner' => 'Investor', 'increases' => 'Investment', 'decreases' => 'Loss recognised, capital returned'],
            'BUSINESS_CAPITAL' => ['meaning' => 'The business partner\'s capital at risk in a Musharakah.', 'owner' => 'Business', 'increases' => 'Business capital contribution received', 'decreases' => 'Loss recognised, capital returned'],
            'VENTURE_CAPITAL' => ['meaning' => 'Participant capital delivered to the venture, carried at cost and written down by recognised loss. An investment interest, not a loan receivable from the business.', 'owner' => 'Participants (investor and business capital)', 'increases' => 'Capital deployment', 'decreases' => 'Capital returned by the business, loss recognised'],
            'PROJECT_FUNDS' => ['meaning' => 'INTERIM proceeds (and recoveries) received from the business and held pending the final determination. Not final profit.', 'owner' => 'Participants', 'increases' => 'Interim proceeds, recovery received', 'decreases' => 'Profit/recovery distributed at settlement'],
            'BUSINESS_FUNDS' => ['meaning' => 'Settlement payable to the business, created only by a valid settlement.', 'owner' => 'Business', 'increases' => 'Settlement (capital returned, profit share)', 'decreases' => 'Payout'],
            'CLEARING' => ['meaning' => 'Technical suspense. NEVER a loss account; must be zero.', 'owner' => 'None', 'increases' => 'Nothing in current flows', 'decreases' => 'n/a'],
            'CAPITAL_DEPLOYED' => ['meaning' => 'LEGACY: offset account of the earlier funding design. Retired from new flows.', 'owner' => 'None', 'increases' => 'No new postings', 'decreases' => 'No new postings'],
            'MURABAHA_INVENTORY' => ['meaning' => 'Goods the platform owns after purchase, at cost.', 'owner' => 'Platform', 'increases' => 'Purchase', 'decreases' => 'Sale'],
            'MURABAHA_RECEIVABLE' => ['meaning' => 'The buyer\'s debt, existing only after a valid sale.', 'owner' => 'Platform', 'increases' => 'Sale', 'decreases' => 'Buyer payment'],
            'MURABAHA_SALE_PROFIT' => ['meaning' => 'Disclosed Murabaha sale profit.', 'owner' => 'Platform', 'increases' => 'Sale', 'decreases' => 'n/a'],
            'PLATFORM_FEES' => ['meaning' => 'Platform fees (not used).', 'owner' => 'Platform', 'increases' => 'n/a', 'decreases' => 'n/a'],
        ];
    }

    /** @return array<string, array{event: string, shariah: string, debit: string, credit: string, owner: string, evidence: string, lifecycle: string}> */
    public static function transactions(): array
    {
        $t = fn (string $event, string $shariah, string $dr, string $cr, string $owner, string $evidence, string $life) => compact('event', 'shariah', 'dr', 'cr', 'owner', 'evidence', 'life') + ['debit' => $dr, 'credit' => $cr, 'lifecycle' => $life];

        return [
            'DEPOSIT' => $t('Client cash received and verified', 'Custody of the client\'s money (contract of custody under review)', 'CUSTODY_CASH', 'INVESTOR_AVAILABLE', 'Investor', 'Verified deposit record', 'Any time after verification'),
            'INVESTMENT' => $t('Investor commits capital to a project', 'Capital contribution at risk under the executed participation agreement', 'INVESTOR_AVAILABLE', 'INVESTOR_INVESTED', 'Investor', 'Executed participation agreement', 'Only while the project is open for funding'),
            'MUSHARAKAH_CAPITAL' => $t('Business partner contributes its Musharakah capital', 'Partner capital contribution, before operations start', 'CUSTODY_CASH', 'BUSINESS_CAPITAL', 'Business', 'Contribution record and bank evidence', 'Once per contract, before activation'),
            'CAPITAL_DEPLOYMENT' => $t('Capital is delivered to the business for the venture', 'Delivery of Ras-ul-Mal / partnership capital', 'VENTURE_CAPITAL', 'CUSTODY_CASH', 'Participants', 'Delivery reference and executed agreements', 'Once, after activation'),
            'VENTURE_CAPITAL_RETURN' => $t('Business returns capital at or before liquidation', 'Recovery of capital value (not a repayment obligation of the business)', 'CUSTODY_CASH', 'VENTURE_CAPITAL', 'Participants', 'Receipt reference', 'After deployment, up to the deployed amount'),
            'INTERIM_PROCEEDS' => $t('Business remits interim proceeds', 'Advance/interim proceeds held pending the final result (not final profit)', 'CUSTODY_CASH', 'PROJECT_FUNDS', 'Participants', 'Receipt reference', 'After deployment, until settlement'),
            'LOSS_RECOGNITION' => $t('Ordinary commercial loss is recognised', 'Loss borne by the capital partners; no debt of any party', 'INVESTOR_INVESTED / BUSINESS_CAPITAL', 'VENTURE_CAPITAL', 'Participants', 'Settlement with a determined result', 'Only inside a settlement'),
            'PRINCIPAL_RETURN' => $t('Remaining capital becomes withdrawable', 'Capital recovered after loss; not a guaranteed principal', 'INVESTOR_INVESTED', 'INVESTOR_AVAILABLE', 'Investor', 'Settlement', 'Only inside a settlement'),
            'BUSINESS_CAPITAL_RETURN' => $t('Business partner\'s remaining capital becomes payable', 'Capital recovered after loss', 'BUSINESS_CAPITAL', 'BUSINESS_FUNDS', 'Business', 'Settlement', 'Only inside a settlement'),
            'PROFIT_DISTRIBUTION' => $t('Final profit is distributed', 'Agreed share of actual profit after the final determination', 'PROJECT_FUNDS', 'INVESTOR_AVAILABLE / BUSINESS_FUNDS', 'Participants', 'Settlement', 'Only inside a settlement'),
            'RECOVERY_RECEIPT' => $t('A recognised manager liability is paid', 'Compensation for loss caused by established fault; not profit', 'CUSTODY_CASH', 'PROJECT_FUNDS', 'Participants', 'Recovery record with liability recognised', 'Only after liability is recognised'),
            'RECOVERY_DISTRIBUTION' => $t('A recovery is passed to the investors who bore the loss', 'Compensation returned to those who bore the loss', 'PROJECT_FUNDS', 'INVESTOR_AVAILABLE', 'Investor', 'Recovery record', 'After receipt'),
            'WITHDRAWAL' => $t('Cash is paid out to the client', 'Return of the client\'s own custody money', 'INVESTOR_PENDING', 'CUSTODY_CASH', 'Investor', 'Approved withdrawal and bank reference', 'After approval'),
            'REFUND' => $t('A withdrawal is released back', 'Reversal of a pending withdrawal', 'INVESTOR_PENDING', 'INVESTOR_AVAILABLE', 'Investor', 'Rejected/cancelled withdrawal', 'Pending withdrawals only'),
            'ADJUSTMENT' => $t('Administrative adjustment', 'Not a financial event of any aqd; needs documented approval', 'per entry', 'per entry', 'Per entry', 'Written approval', 'Admin only'),
            'REVERSAL' => $t('Opposite entry of an earlier transaction', 'Correction; history is never edited', 'per original', 'per original', 'Per original', 'Reference to the original', 'Once per original'),
            'MURABAHA_PURCHASE' => $t('Platform buys the asset from the supplier', 'Acquisition by the seller before the sale (platform-owned funds)', 'MURABAHA_INVENTORY', 'PLATFORM_CASH', 'Platform', 'Supplier invoice and payment evidence', 'Before ownership and qabd'),
            'MURABAHA_SALE' => $t('Sale to the buyer at disclosed cost plus profit', 'Sale of an owned, possessed asset; creates the receivable', 'MURABAHA_RECEIVABLE', 'MURABAHA_INVENTORY / MURABAHA_SALE_PROFIT', 'Platform', 'Executed sale agreement, ownership and qabd records', 'Only after qabd and the risk-bearing period'),
            'MURABAHA_PAYMENT' => $t('Buyer pays an installment', 'Settlement of the sale debt', 'PLATFORM_CASH', 'MURABAHA_RECEIVABLE', 'Platform', 'Payment reference', 'After the sale'),
            'PROJECT_FUNDING' => $t('LEGACY gross-up funding leg', 'None: retired design', 'CAPITAL_DEPLOYED', 'PROJECT_FUNDS', 'None', 'n/a', 'Legacy only'),
            'CAPITAL_RELEASE' => $t('LEGACY release of the gross-up leg', 'None: retired design', 'PROJECT_FUNDS', 'CAPITAL_DEPLOYED', 'None', 'n/a', 'Legacy only'),
            'BUSINESS_REMITTANCE' => $t('LEGACY undifferentiated business remittance', 'None: retired design (use CAPITAL/INTERIM components)', 'PLATFORM_CASH', 'PROJECT_FUNDS', 'None', 'n/a', 'Legacy only'),
            'CAPITAL_LOSS' => $t('LEGACY business loss credited to platform cash', 'None: retired design', 'PROJECT_FUNDS', 'PLATFORM_CASH', 'None', 'n/a', 'Legacy only'),
        ];
    }

    /** @return list<string> cases that lack documentation (should always be empty) */
    public static function undocumented(): array
    {
        $missing = [];
        foreach (LedgerAccountType::cases() as $c) {
            isset(self::accounts()[$c->value]) || $missing[] = 'account:'.$c->value;
        }
        foreach (TransactionType::cases() as $c) {
            isset(self::transactions()[$c->value]) || $missing[] = 'transaction:'.$c->value;
        }

        return $missing;
    }
}
