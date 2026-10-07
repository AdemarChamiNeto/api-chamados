<?php

declare(strict_types=1);

namespace Chamados\Domain;

enum Category: string
{
    case Hardware = 'hardware';
    case Software = 'software';
    case Acesso = 'acesso';
    case Rede = 'rede';
    case Impressao = 'impressao';
    case Outros = 'outros';
}
