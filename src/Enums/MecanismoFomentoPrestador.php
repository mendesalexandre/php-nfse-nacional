<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Mecanismo de apoio/fomento ao Comércio Exterior utilizado pelo
 * PRESTADOR do serviço — campo `<mecAFComexP>` dentro de `<comExt>`
 * (XSD V1.01 linha 1403).
 */
enum MecanismoFomentoPrestador: string
{
    case Desconhecido = '00';
    case Nenhum = '01';
    /** ACC — Adiantamento sobre Contrato de Câmbio. */
    case Acc = '02';
    /** ACE — Adiantamento sobre Cambiais Entregues. */
    case Ace = '03';
    case BndesEximPosEmbarque = '04';
    case BndesEximPreEmbarque = '05';
    /** FGE — Fundo de Garantia à Exportação. */
    case Fge = '06';
    /** PROEX — Equalização. */
    case ProexEqualizacao = '07';
    /** PROEX — Financiamento. */
    case ProexFinanciamento = '08';
}
