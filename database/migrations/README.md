# Migrações

Alterações incrementais aplicadas ao banco, na ordem em que foram executadas.

Numa instalação nova, use `../schema.sql`, que já contempla todas elas. Estes
arquivos servem como histórico e para atualizar uma instalação existente.

Cada arquivo foi escrito para ser executado **um bloco por vez** no phpMyAdmin.
Alguns contêm `ALTER TABLE` com várias colunas: se aparecer o erro
`#1060 Duplicate column`, a coluna já existe — remova aquela linha e execute
o restante.
