<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Controla o bloco "Canhoto" opcional da DANFSe (item 2.1.13 do Anexo I,
 * NT 008/2026): "Data de cientificação", "Identificação e Assinatura",
 * "Nº da NFS-e / Chave da NFS-e".
 *
 * O leiaute não define o conteúdo dos dois primeiros campos — cada emissor
 * decide. Duas convenções observadas na prática:
 *
 * - **EmBranco**: linhas vazias, pra assinatura física do tomador ao
 *   receber o documento impresso (uso clássico de "canhoto").
 * - **PreenchidoAutomaticamente**: preenche com a data/hora de emissão da
 *   NFS-e (`dhEmi`), sem exigir assinatura física — só um registro formal.
 */
enum TipoCanhoto: string
{
    case EmBranco = 'em_branco';
    case PreenchidoAutomaticamente = 'preenchido_automaticamente';
}
