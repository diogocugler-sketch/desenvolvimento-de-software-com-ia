# Treino da semana

## Executar no Laragon

1. Inicie o Apache e o MySQL pelo Laragon.
2. No phpMyAdmin, importe o arquivo `banco.sql`. Ele cria o banco `treino_app` e as tabelas necessárias.
3. Abra a pasta pelo servidor, por exemplo `http://localhost/desenvolvimento-de-software-com-ia/aula2%20dia%2027_10/`.
4. Crie seu cadastro na página. Use um e-mail válido e uma senha de pelo menos 8 caracteres.

O PHP usa por padrão MySQL em `127.0.0.1`, banco `treino_app`, usuário `root` e senha vazia (padrões comuns do Laragon). Para outra configuração, defina `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` no ambiente do PHP.

Não abra `index.html` diretamente como arquivo: PHP e as rotas da API precisam ser servidos pelo Apache.

## API

As rotas estão em `api.php?action=...`. Cadastro e login criam uma sessão PHP; o cookie é HttpOnly e SameSite=Lax.

- `POST register`: `{ "nome": "Nome", "email": "email@exemplo.com", "senha": "senha-segura" }`
- `POST login`: `{ "email": "email@exemplo.com", "senha": "senha-segura" }`
- `GET me`: retorna o cliente autenticado.
- `POST logout`: encerra a sessão.
- `GET workout`: retorna os registros de treino do cliente autenticado.
- `PUT workout`: salva `{ "done": {}, "loads": {}, "presenca": {} }` para o cliente autenticado.

As senhas são armazenadas com `password_hash`; os dados de treino são separados por cliente no banco.
