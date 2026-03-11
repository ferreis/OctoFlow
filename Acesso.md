## Como acessar os servicos
Ao subir o ambiente com docker compose up, estas sao as portas abertas no host:

| Servico | Porta no host | Como acessar |
| --- | --- | --- |
| Aplicacao via Nginx | 4481 | https://localhost:4481/ModFederation |
| Aplicacao via Nginx | 4481 | https://localhost:4481/ModFederation-mf |
| PostgreSQL | 14952 | Host: localhost, porta: 14952, banco: sina_db, usuario: root, senha: root |
| MailCatcher | 11081 | http://localhost:11081 |