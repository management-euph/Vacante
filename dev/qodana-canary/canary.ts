/**
 * Qodana canary: code with known defects that only Qodana is placed to find.
 *
 * WHY: to check that Qodana really inspects the repo. PHP Lint, PHPStan,
 * Rector and php-cs-fixer read only PHP, and ESLint lints only .js/.jsx
 * (eslint.config.mjs), so none of them sees this TypeScript file. Each
 * function below holds one defect that a Qodana JavaScript/TypeScript
 * inspection reports; a scan that reports none of them did not inspect it.
 *
 * Expected in the Qodana report, one each:
 *  - JSComparisonWithNaN        isMissing(): comparing with NaN is always false
 *  - TypeScriptValidateTypes    label(): a string assigned to a number
 *  - UnreachableCodeJS          total(): a statement after return
 *  - InfiniteRecursionJS        countdown(): calls itself on every path
 *
 * Nothing imports this file; it is never built or shipped.
 */

export function isMissing(value: number): boolean {
    return value === NaN;
}

export function label(nights: number): string {
    const count: number = 'seven';
    return `${nights + count} nights`;
}

export function total(prices: number[]): number {
    return prices.reduce((sum, price) => sum + price, 0);
    console.log('never logged');
}

export function countdown(from: number): number {
    return countdown(from - 1);
}
