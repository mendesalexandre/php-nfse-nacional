<?php

declare(strict_types=1);

namespace PhpNfseNacional;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseDados;
use PhpNfseNacional\Dps\DpsBuilder;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Intermediario;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\DTO\MotivoSubstituicao;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Enums\DanfseVersao;
use PhpNfseNacional\Sefin\RespostaDfe;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;
use PhpNfseNacional\Services\CancelamentoService;
use PhpNfseNacional\Services\ConsultaService;
use PhpNfseNacional\Services\DanfseService;
use PhpNfseNacional\Services\DfeService;
use PhpNfseNacional\Services\DownloadService;
use PhpNfseNacional\Services\EmissaoService;
use PhpNfseNacional\Services\ManifestacaoService;
use PhpNfseNacional\Services\SubstituicaoService;

/**
 * Facade unificado do SDK — API achatada (sem `->servico()->acao()`).
 *
 * Uso típico:
 *
 *   $nfse = NFSe::create($config, $cert);
 *   $resp = $nfse->emitir($identificacao, $tomador, $servico, $valores);
 *   $resp = $nfse->cancelar($chave, MotivoCancelamento::ErroEmissao, '...');
 *   $resp = $nfse->consultar($chave);
 *   $pdf  = $nfse->danfseLocal($xml);
 *
 * Os Services internos (EmissaoService, CancelamentoService, etc.) continuam
 * disponíveis como classes públicas — quem usa DI granular pode instanciá-los
 * diretamente, sem passar pela facade. Eles ficam em `PhpNfseNacional\Services\`.
 */
final class NFSe
{
    private function __construct(
        private readonly EmissaoService $emissaoService,
        private readonly ConsultaService $consultaService,
        private readonly CancelamentoService $cancelamentoService,
        private readonly SubstituicaoService $substituicaoService,
        private readonly ManifestacaoService $manifestacaoService,
        private readonly DownloadService $downloadService,
        private readonly DanfseService $danfseService,
        private readonly DfeService $dfeService,
    ) {}

    /**
     * Cria a facade com toda a árvore de dependências resolvida.
     *
     * @param ClientInterface|null $http   Cliente PSR-18 (default: Guzzle com mTLS)
     * @param LoggerInterface|null $logger Logger PSR-3 (default: NullLogger)
     */
    public static function create(
        Config $config,
        Certificate $certificate,
        ?ClientInterface $http = null,
        ?LoggerInterface $logger = null,
    ): self {
        $logger ??= new NullLogger();
        $endpoints = new SefinEndpoints($config->ambiente);
        $client = new SefinClient($config, $certificate, $endpoints, $http, $logger);
        $signer = new Signer($certificate);

        $dpsBuilder = new DpsBuilder($config);
        $eventoBuilder = new EventoBuilder($config);

        return new self(
            emissaoService: new EmissaoService($config, $dpsBuilder, $signer, $client, $logger),
            consultaService: new ConsultaService($client, $endpoints),
            cancelamentoService: new CancelamentoService($eventoBuilder, $signer, $client, $endpoints, $logger),
            substituicaoService: new SubstituicaoService($eventoBuilder, $signer, $client, $endpoints, $logger),
            manifestacaoService: new ManifestacaoService($eventoBuilder, $signer, $client, $endpoints, $logger),
            downloadService: new DownloadService($client, $endpoints),
            danfseService: new DanfseService(),
            dfeService: new DfeService($client, $certificate),
        );
    }

    // ───────── Emissão ─────────

    public function emitir(
        Identificacao $identificacao,
        Tomador $tomador,
        Servico $servico,
        Valores $valores,
        ?Intermediario $intermediario = null,
    ): SefinResposta {
        return $this->emissaoService->emitir(
            $identificacao,
            $tomador,
            $servico,
            $valores,
            $intermediario,
        );
    }

    // ───────── Consulta ─────────

    public function consultar(string $chaveAcesso): SefinResposta
    {
        return $this->consultaService->consultarNfse($chaveAcesso);
    }

    public function consultarDps(string $chaveAcesso): SefinResposta
    {
        return $this->consultaService->consultarDps($chaveAcesso);
    }

    public function consultarEventos(
        string $chaveAcesso,
        ?string $tipoEvento = null,
        ?int $nSequencial = null,
    ): SefinResposta {
        return $this->consultaService->consultarEventos($chaveAcesso, $tipoEvento, $nSequencial);
    }

    // ───────── Cancelamento ─────────

    public function cancelar(
        string $chaveAcesso,
        MotivoCancelamento $motivo,
        string $justificativa,
    ): SefinResposta {
        return $this->cancelamentoService->cancelar($chaveAcesso, $motivo, $justificativa);
    }

    // ───────── Substituição ─────────

    public function substituir(
        string $chaveOriginal,
        string $chaveSubstituta,
        MotivoSubstituicao $motivo,
        string $justificativa = '',
    ): SefinResposta {
        return $this->substituicaoService->substituir(
            $chaveOriginal,
            $chaveSubstituta,
            $motivo,
            $justificativa,
        );
    }

    // ───────── Manifestação ─────────

    public function confirmar(
        string $chaveAcesso,
        AutorManifestacao $autor,
    ): SefinResposta {
        return $this->manifestacaoService->confirmar($chaveAcesso, $autor);
    }

    public function rejeitar(
        string $chaveAcesso,
        AutorManifestacao $autor,
        MotivoRejeicao $motivo,
        string $xMotivo = '',
    ): SefinResposta {
        return $this->manifestacaoService->rejeitar($chaveAcesso, $autor, $motivo, $xMotivo);
    }

    public function anularRejeicao(
        string $chaveAcesso,
        string $cpfAgente,
        string $idEvManifRej,
        string $xMotivo,
    ): SefinResposta {
        return $this->manifestacaoService->anularRejeicao(
            $chaveAcesso,
            $cpfAgente,
            $idEvManifRej,
            $xMotivo,
        );
    }

    // ───────── Download ─────────

    public function baixarXml(string $chaveAcesso): string
    {
        return $this->downloadService->xmlNfse($chaveAcesso);
    }

    public function baixarPdf(string $chaveAcesso, int $tentativas = 3): string
    {
        return $this->downloadService->pdfDanfse($chaveAcesso, $tentativas);
    }

    /**
     * Verifica se um DPS já foi enviado ao SEFIN (HEAD /dps/{id}). Útil
     * pra evitar dupla emissão antes de chamar `emitir()`.
     */
    public function verificarDps(string $idDps): bool
    {
        return $this->downloadService->verificarDps($idDps);
    }

    /**
     * Lista todos os eventos vinculados a uma NFS-e (cancelamento,
     * substituição, manifestações). Útil para auditoria.
     *
     * @return array<int, mixed>
     */
    public function listarEventos(string $chaveAcesso): array
    {
        return $this->downloadService->listarEventosNfse($chaveAcesso);
    }

    /**
     * True se a NFS-e tem evento de **CANCELAMENTO** ou **SUBSTITUICAO**
     * vinculado. Forma canônica de detectar cancelamento — `consultar()`
     * retorna cStat=100 mesmo após cancelar (cancelamento é evento, não
     * altera status da emissão original).
     */
    public function estaCancelada(string $chaveAcesso): bool
    {
        return $this->downloadService->nfseEstaCancelada($chaveAcesso);
    }

    // ───────── DFe (Distribuição de Documentos Eletrônicos) ─────────

    /**
     * Sincroniza DFes pendentes na "caixa postal" do CNPJ do prestador.
     * Use `$ultimoNsu` da última chamada bem-sucedida pra sincronização
     * incremental (só pega DFes novos).
     */
    public function sincronizarDfe(int $ultimoNsu = 0, int $maxPaginas = 20): RespostaDfe
    {
        return $this->dfeService->sincronizar($ultimoNsu, $maxPaginas);
    }

    // ───────── DANFSe local ─────────

    public function danfseLocal(
        string $xmlNfse,
        ?DanfseCustomizacao $custom = null,
        DanfseVersao $versao = DanfseVersao::V2,
    ): string {
        return $this->danfseService->gerarDoXml($xmlNfse, $custom, $versao);
    }

    public function danfseLocalDeDados(
        DanfseDados $dados,
        ?DanfseCustomizacao $custom = null,
        DanfseVersao $versao = DanfseVersao::V2,
    ): string {
        return $this->danfseService->gerarDeDados($dados, $custom, $versao);
    }
}
