<?php

namespace App\Finance;

final class FinanceErrorMessages
{
    // Entrada de dados
    public const ENTRY_TITLE_REQUIRED = 'O titulo do lancamento e obrigatorio.';
    public const ENTRY_TYPE_INVALID = 'O tipo de lancamento e invalido.';
    public const ENTRY_NOT_FOUND = 'Lancamento nao encontrado.';
    public const ENTRY_ALREADY_SETTLED = 'Este lancamento ja esta totalmente baixado.';
    public const ENTRY_CANCELED_OR_NEGOTIATED = 'Lancamentos cancelados ou negociados nao podem receber baixas.';
    public const ENTRY_FORECAST_EXPIRED = 'Recebivel futuro vencido deve ser atualizado para OVERDUE.';

    // Valores financeiros
    public const AMOUNT_MUST_BE_POSITIVE = 'O valor deve ser maior que zero.';
    public const AMOUNT_CANNOT_BE_NEGATIVE = 'O valor nao pode ser negativo.';
    public const AMOUNT_CANNOT_BE_ZERO = 'O valor nao pode ser zero.';
    public const AMOUNT_CANNOT_EXCEED_REMAINING = 'O valor nao pode exceder o saldo restante.';
    public const AMOUNT_LOWER_THAN_SETTLED = 'O valor nao pode ser menor que o valor ja baixado.';
    public const EXPECTED_AMOUNT_MUST_BE_POSITIVE = 'O valor esperado deve ser maior que zero.';
    public const SETTLEMENT_AMOUNT_MUST_BE_POSITIVE = 'O valor da baixa deve ser maior que zero.';
    public const SETTLEMENT_FAILED = 'Falha ao processar a baixa.';
    public const SETTLEMENT_ALLOCATION_FAILED = 'Falha ao alocar valor da baixa entre parcelas.';
    public const SETTLEMENT_READ_FAILED = 'Falha ao ler baixa criada.';
    public const SETTLEMENT_AMOUNT_CANNOT_EXCEED_REMAINING = 'O valor da baixa nao pode exceder o saldo restante.';

    // Categorias
    public const CATEGORY_REQUIRED = 'A categoria e obrigatoria.';
    public const CATEGORY_NOT_FOUND = 'Categoria nao encontrada para o usuario atual.';
    public const CATEGORY_INACTIVE = 'Categorias inativas nao podem ser usadas em registros financeiros.';
    public const CATEGORY_DUPLICATE = 'Ja existe uma categoria com este nome e tipo.';

    // Contas bancarias
    public const BANK_ACCOUNT_REQUIRED = 'A conta bancaria e obrigatoria.';
    public const BANK_ACCOUNT_NOT_FOUND = 'Conta bancaria nao encontrada para o usuario atual.';
    public const BANK_ACCOUNT_INACTIVE = 'Contas bancarias inativas nao podem ser usadas em novos lancamentos ou baixas.';
    public const BANK_ACCOUNT_DUPLICATE = 'Ja existe uma conta bancaria com este nome.';

    // Cartao de credito
    public const CREDIT_CARD_ONLY_FOR_PAYABLE = 'Baixa por cartao de credito e permitida apenas para contas a pagar.';
    public const CREDIT_CARD_ACCOUNT_REQUIRED = 'Uma conta de cartao de credito deve ser selecionada para baixa por credito.';
    public const CREDIT_CARD_ACCOUNT_INVALID = 'A conta selecionada nao e um cartao de credito.';

    // Tipos de baixa
    public const SETTLEMENT_TYPE_INVALID = 'O tipo de baixa e invalido.';
    public const SETTLEMENT_TYPE_REQUIRED = 'O tipo de baixa e obrigatorio.';

    // Moedas e taxas
    public const FX_RATE_REQUIRED = 'A taxa de cambio deve ser informada para lancamentos em moeda diferente de BRL.';
    public const CURRENCY_CODE_INVALID = 'O codigo da moeda deve ter 3 letras.';
    public const MANUAL_RATE_MUST_BE_POSITIVE = 'A taxa manual deve ser maior que zero.';

    // Datas
    public const DATE_INVALID = 'A data informada e invalida.';
    public const DATE_REQUIRED = 'O campo "%s" e obrigatorio.';
    public const DATE_FIELD_INVALID = 'O campo "%s" tem uma data invalida.';
    public const DUE_DATE_CANNOT_BE_EARLIER = 'A data de vencimento nao pode ser anterior a data de inicio.';
    public const END_DATE_CANNOT_BE_EARLIER = 'A data final nao pode ser anterior a data de inicio.';

    // Recorrencias
    public const RECURRING_TITLE_REQUIRED = 'O titulo da regra recorrente e obrigatorio.';
    public const RECURRING_AMOUNT_MUST_BE_POSITIVE = 'O valor da regra recorrente deve ser maior que zero.';
    public const RECURRING_FREQUENCY_UNSUPPORTED = 'Apenas a frequencia MONTHLY e suportada nesta versao.';
    public const RECURRING_DAY_INVALID = 'O dia do mes deve estar entre 1 e 31.';
    public const RECURRING_INACTIVE = 'Regras recorrentes inativas nao podem gerar lancamentos.';
    public const RECURRING_NOT_FOUND = 'Regra recorrente nao encontrada.';
    public const RECURRING_TYPE_REQUIRED = 'O tipo recorrente e obrigatorio.';
    public const RECURRING_TYPE_NOT_FOUND = 'Tipo recorrente nao encontrado para o usuario atual.';
    public const RECURRING_TYPE_DEPRECATED = 'O tipo recorrente "Mensal" esta descontinuado e nao pode ser criado.';
    public const RECURRING_TYPE_CANNOT_DELETE = 'Nao e possivel excluir tipo recorrente vinculado a regras existentes.';

    // Categorias de recorrencia
    public const RECURRING_CATEGORY_REQUIRED = 'A categoria e obrigatoria para regras recorrentes.';

    // Planos de investimento
    public const INVESTMENT_TYPE_INVALID = 'O tipo de investimento e invalido.';
    public const INVESTMENT_YIELD_MODE_INVALID = 'O modo de rendimento e invalido.';

    // Exportacao
    public const EXPORT_TYPE_INVALID = 'O tipo de exportacao e invalido.';
    public const EXPORT_STATUS_INVALID = 'O status da exportacao e invalido.';

    // Open Finance
    public const OPEN_FINANCE_STATUS_INVALID = 'O status da conexao Open Finance e invalido.';
    public const OPEN_FINANCE_CONNECTION_NOT_FOUND = 'Conexao Open Finance nao encontrada.';
    public const OPEN_FINANCE_PROVIDER_NOT_FOUND = 'Proveedor Open Finance nao encontrado ou inativo.';
    public const OPEN_FINANCE_PROVIDER_REQUIRED = 'providerId ou providerCode e obrigatorio para criar uma conexao.';

    // Geral
    public const INVALID_USER_CONTEXT = 'Contexto de usuario invalido.';
    public const FIELD_REQUIRED = 'O campo "%s" e obrigatorio.';
    public const FIELD_INVALID = 'O campo "%s" e invalido.';
    public const NUMERIC_FIELD_REQUIRED = 'O campo "%s" deve ser numerico.';

    // Permissoes
    public const PERMISSION_DENIED = 'Permissao negada para esta operacao.';
    public const FINANCE_READ_REQUIRED = 'Permissao de leitura financeira necessaria.';
    public const FINANCE_WRITE_REQUIRED = 'Permissao de escrita financeira necessaria.';

    // Importacao
    public const IMPORT_CONFIRMATION_REQUIRED = 'Confirmacao forte e necessaria para importacao de dados financeiros.';
    public const IMPORT_REPLACE_CONFIRMATION = 'Use "SUBSTITUIR_DADOS_FINANCEIROS" no campo confirmReplaceExisting para confirmar.';

    private function __construct()
    {
    }
}
