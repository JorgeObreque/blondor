<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Blondor - Política de abono
 *
 * Regla acordada:
 *
 *   - Abono de $15.000 cuando el precio final (variante o servicio) es de $30.000 o más.
 *   - Abono de $8.000 cuando el precio final es menor de $30.000.
 *   - El abono nunca pasa del total: si el servicio es más barato, se cobra el total.
 *   - La clienta elige abono o servicio completo, siempre que el precio final sea exacto.
 *   - Con precio "desde" (sin precio final exacto) solo se puede pagar abono: el mínimo no es el total.
 *
 * Esta clase no toca la base de datos: solo calcula. Así la política se puede probar sola.
 * ---------------------------------------------------------------------------- */

/**
 * Deposit policy library.
 *
 * @package Libraries
 */
class Deposit_policy
{
    /**
     * Desde este precio el abono es el alto.
     */
    const HIGH_THRESHOLD = 30000;

    /**
     * Abono del servicio caro.
     */
    const HIGH_DEPOSIT = 15000;

    /**
     * Abono del servicio barato.
     */
    const LOW_DEPOSIT = 8000;

    /**
     * Modo de pago: abono.
     */
    const MODE_DEPOSIT = 'deposit';

    /**
     * Modo de pago: servicio completo.
     */
    const MODE_FULL = 'full';

    /**
     * The options the customer can pay, for the given price.
     *
     * @param float|null $total Final price. NULL when it is not known yet ("desde").
     * @param float $reference Price used to decide the deposit bracket when the total is unknown (the "desde" value).
     *
     * @return array {
     *     @var float $deposit Deposit amount.
     *     @var float|null $total Final price (NULL when unknown).
     *     @var bool $exact Whether the final price is exact.
     *     @var array $options [{mode, amount, due, label}]: what the customer can choose.
     * }
     */
    public function options(?float $total, float $reference = 0.0): array
    {
        $exact = $total !== null && $total > 0;

        $reference = $exact ? $total : max(0.0, $reference);

        if ($reference <= 0) {
            return [
                'deposit' => 0.0,
                'total' => $total,
                'exact' => false,
                'options' => [],
            ];
        }

        $deposit = $reference >= self::HIGH_THRESHOLD ? self::HIGH_DEPOSIT : self::LOW_DEPOSIT;

        // The deposit never goes over the price: the cheapest services are paid in full.
        if ($exact) {
            $deposit = min($deposit, $total);
        } else {
            // The final price is unknown but it is never lower than the "desde" value.
            $deposit = min($deposit, $reference);
        }

        $options = [
            [
                'mode' => self::MODE_DEPOSIT,
                'amount' => $deposit,
                // With an unknown total the balance is unknown too: it is settled at the salon.
                'due' => $exact ? round($total - $deposit, 2) : null,
                'label' => 'Abono',
            ],
        ];

        if ($exact) {
            $options[] = [
                'mode' => self::MODE_FULL,
                'amount' => $total,
                'due' => 0.0,
                'label' => 'Pago completo',
            ];
        }

        return [
            'deposit' => $deposit,
            'total' => $exact ? $total : null,
            'exact' => $exact,
            'options' => $options,
        ];
    }

    /**
     * The amount to charge for the chosen mode. Always computed here, never taken from the browser.
     *
     * @param array $options The result of options().
     * @param string $mode Chosen mode ("deposit" or "full").
     *
     * @return float|null The amount, or NULL when the mode is not allowed.
     */
    public function amount(array $options, string $mode): ?float
    {
        foreach ($options['options'] as $option) {
            if ($option['mode'] === $mode) {
                return (float) $option['amount'];
            }
        }

        return null;
    }

    /**
     * The balance left after paying the chosen mode.
     *
     * @param array $options The result of options().
     * @param string $mode Chosen mode.
     *
     * @return float|null NULL when the final price is unknown.
     */
    public function balance(array $options, string $mode): ?float
    {
        foreach ($options['options'] as $option) {
            if ($option['mode'] === $mode) {
                return $option['due'];
            }
        }

        return null;
    }
}
