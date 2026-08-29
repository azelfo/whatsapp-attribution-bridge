# WhatsApp Attribution Bridge 0.2.1

Plugin beta para ligar a origem de um clique no WordPress ao contato criado quando a mensagem chega pelo WhatsApp no GoHighLevel.

## Escopo deste MVP

- Captura first-touch e last-touch no `localStorage`.
- Captura UTMs, IDs de campanha, `gclid`, `gbraid`, `wbraid`, `fbclid` e referrer.
- Cria mensagens rastreáveis no painel do WordPress.
- Injeta duas cópias invisíveis de um token aleatório na mensagem.
- Registra o clique com `sendBeacon()` sem bloquear a abertura do WhatsApp.
- Preserva first-touch já preenchido no contato e atualiza last-touch.
- Atualiza o contato usando a API de Contatos v3 e adiciona uma tag após a atribuição.
- Repete automaticamente integrações pendentes após falhas transitórias do HighLevel.
- Falha de forma aberta: sem JavaScript, o link continua abrindo o WhatsApp com a mensagem visível.
- Não solicita diretamente nome, telefone ou conteúdo da conversa. URLs são gravadas sem query string ou fragmento; UTMs e IDs permitidos ficam em campos separados. As landing pages não devem colocar dados pessoais no caminho da URL.

O MVP não faz casamento temporal. Mensagens cujo token seja apagado entram normalmente no HighLevel, mas ficam sem atribuição automática. Inferência temporal só deve ser adicionada depois de medir a perda real, em campos separados dos dados exatos.

## Requisitos

- WordPress 6.0 ou superior.
- PHP 7.4 ou superior.
- HTTPS.
- WhatsApp conectado ao mesmo número usado nas mensagens do plugin.
- Subconta HighLevel com permissão para criar workflows e Private Integrations.
- Private Integration Token com `contacts.readonly` e `contacts.write`.

## Instalação

1. Compacte a pasta `whatsapp-attribution-bridge` em ZIP.
2. No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**.
3. Instale e ative.
4. Abra **WhatsApp Attribution**.
5. Informe o Location ID, token, segredo do webhook, retenção e mapa de campos.
6. Cadastre uma mensagem rastreável com o número exatamente igual ao conectado ao HighLevel.
7. Ative o rastreamento somente depois de concluir os testes de homologação.

Para evitar que o Private Integration Token apareça em backups do banco, adicione ao `wp-config.php`:

```php
define('WAB_HL_TOKEN', 'pit-SEU-TOKEN');
```

O campo do painel existe apenas como fallback para o beta.

## Uso nos botões

A tela de mensagens fornece um link seguro parecido com:

```text
https://wa.me/5571999999999?text=Ol%C3%A1...#wab=agendamento-geral
```

Esse link pode ser colado no Elementor. Se o plugin estiver desligado ou o JavaScript falhar, ele continua sendo um link normal do WhatsApp.

Em conteúdo WordPress também é possível usar:

```text
[wab_whatsapp message="agendamento-geral" label="Agendar pelo WhatsApp" class="meu-botao"]
```

## Workflow no HighLevel

Gatilho:

```text
Customer Replied
Reply Channel = WhatsApp
Contato não possui a tag wab-attribution-processed
```

Adicione uma espera de 3 segundos e depois um webhook `POST` para a URL mostrada no painel do plugin. Para cobrir a rara corrida em que a mensagem chega antes do `sendBeacon()`, adicione uma espera de mais 5 segundos e repita o mesmo webhook. A segunda chamada é segura e não reprocessa uma atribuição concluída.

Header:

```text
Authorization: Bearer SEU_SEGREDO
Content-Type: application/json
```

Body:

```json
{
  "contact_id": "{{contact.id}}",
  "location_id": "{{location.id}}",
  "message": "{{message.body}}"
}
```

O plugin adiciona a tag `wab-attribution-processed` somente depois que o contato é atualizado. A repetição do mesmo webhook é idempotente.

## Mapa de campos

Crie campos de texto no contato do HighLevel e associe seus IDs às chaves abaixo:

```json
{
  "first_source": "ID_DO_CAMPO",
  "first_campaign": "ID_DO_CAMPO",
  "first_term": "ID_DO_CAMPO",
  "first_click_id": "ID_DO_CAMPO",
  "first_landing": "ID_DO_CAMPO",
  "last_source": "ID_DO_CAMPO",
  "last_campaign": "ID_DO_CAMPO",
  "last_term": "ID_DO_CAMPO",
  "last_click_id": "ID_DO_CAMPO",
  "last_landing": "ID_DO_CAMPO",
  "confidence": "ID_DO_CAMPO",
  "method": "ID_DO_CAMPO",
  "message_id": "ID_DO_CAMPO"
}
```

Também são aceitas as chaves opcionais `first_medium`, `first_content`, `last_medium` e `last_content`.

## Testes locais

JavaScript, sem dependências:

```powershell
node .\tests\tracker.test.js
```

PHP, quando o executável estiver disponível:

```powershell
php .\tests\core-test.php
```

Antes de produção, valide os caracteres invisíveis em Android, iOS e WhatsApp Web e confirme se `{{message.body}}` os preserva no webhook. Se o merge field remover os caracteres, o próximo passo é consultar a mensagem original pela Conversations API, não trocar o alfabeto às cegas.

## Atualizações

O plugin se autoatualiza via GitHub, usando a lib [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (`includes/plugin-update-checker/`). O WordPress passa a mostrar o aviso normal de atualização na tela de Plugins e atualiza com um clique, sem precisar do WordPress.org.

Para publicar uma nova versão:

1. Suba a alteração pra `main` no repositório `https://github.com/azelfo/whatsapp-attribution-bridge`.
2. Atualize o número de versão no cabeçalho de `whatsapp-attribution-bridge.php` (`Version:` e a constante `WAB_VERSION`) e neste README.
3. Crie uma tag `git tag vX.Y.Z && git push --tags` (ou uma Release pelo site do GitHub).

Dentro de algumas horas (ou na próxima vez que alguém abrir a tela de Plugins) o WordPress detecta a tag nova e oferece a atualização.

## Segurança e desempenho

- O endpoint público aceita no máximo 30 registros por minuto por IP, em janela fixa, e payloads de até 8 KB. Em Cloudflare, usa `CF-Connecting-IP`.
- O webhook exige segredo comparado com `hash_equals()`.
- O token do HighLevel nunca é enviado ao navegador.
- A tabela armazena somente atribuição, token e ID técnico do contato.
- Registros expiram pela rotina diária de retenção.
- Falhas do HighLevel mantêm o contato vinculado e são tentadas novamente a cada 5 minutos, até 8 tentativas; os últimos erros aparecem no painel.
- Não há chamadas ao HighLevel durante o carregamento da página.
- O plugin só altera links com `#wab=` ou `data-wab-message`.
- Ao desinstalar, os dados são preservados por padrão. A exclusão total precisa ser ativada explicitamente no painel antes da remoção.

## Checklist de homologação

1. Confirmar que o número `wa.me` é o número conectado ao HighLevel.
2. Confirmar recebimento de uma mensagem comum no HighLevel.
3. Confirmar `contact.id`, `location.id` e `message.body` no webhook.
4. Testar uma atualização GET + PUT em contato de teste.
5. Testar URL com UTMs e GCLID.
6. Testar segunda chamada idêntica do webhook.
7. Testar contato que já possui first-touch.
8. Testar com cache, Cloudflare e plugin de segurança ativos.
9. Testar com JavaScript desabilitado: o WhatsApp deve continuar abrindo.
10. Ativar primeiro em uma única landing page.
