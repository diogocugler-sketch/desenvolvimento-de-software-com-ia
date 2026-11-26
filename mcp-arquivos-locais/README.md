# MCP de arquivos locais

Servidor MCP em TypeScript que permite listar diretórios e ler arquivos de texto dentro de uma raiz autorizada. A raiz é definida por `MCP_FILES_ROOT`; caminhos absolutos, travessia com `..` e links simbólicos que escapem dessa raiz são bloqueados.

## Requisitos

- Node.js 20 ou superior
- npm

## Instalação e execução

No PowerShell:

```powershell
cd C:\laragon\www\mcp-arquivos-locais
npm install
npm run build
$env:MCP_FILES_ROOT = "C:\laragon\www\desenvolvimento-de-software-com-ia"
npm start
```

O servidor usa o transporte **stdio**: ele deve ser iniciado por um cliente MCP, não aberto diretamente no navegador. Configure o cliente para executar `node` com o argumento `C:\laragon\www\mcp-arquivos-locais\dist\index.js` e defina `MCP_FILES_ROOT` no ambiente desse processo.

Exemplo de configuração para clientes que usam `mcpServers`:

```json
{
  "mcpServers": {
    "arquivos-locais": {
      "command": "node",
      "args": ["C:\\laragon\\www\\mcp-arquivos-locais\\dist\\index.js"],
      "env": {
        "MCP_FILES_ROOT": "C:\\laragon\\www\\desenvolvimento-de-software-com-ia"
      }
    }
  }
}
```

## Ferramentas MCP

- `list_directory`: lista os nomes e tipos dos itens de um diretório relativo à raiz.
- `read_file`: lê um arquivo de texto de até 1 MB relativo à raiz.

O servidor é somente leitura. Erros de caminho ou acesso são retornados como erros da ferramenta MCP.
