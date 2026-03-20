## Como acessar os servicos
Ao subir o ambiente com docker compose up, estas sao as portas abertas no host:

| Servico | Porta no host | Como acessar |
| --- | --- | --- |
| Aplicacao via Nginx | 4481 | https://localhost:4481/OctoFlow |
| Componentes remotos | 4481 | https://localhost:4481/OctoFlow-mf |
| PostgreSQL | 14953 | Host: localhost, porta: 14953, banco: test_db, usuario: root, senha: root |
| MailCatcher | 11081 | http://localhost:11081 |
