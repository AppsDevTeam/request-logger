# ADT Request Logger

Loguje HTTP požadavky (a volitelně odpovědi) Nette aplikace do tabulek
`request_log` a `request_log_body`.

```
composer require adt/request-logger
```

## Proč

Když se řeší incident nebo reklamace, potřebuješ vědět, co přesně klient
poslal a co dostal zpátky — včetně hlaviček a těla. Logger to ukládá do dvou
tabulek s různou retencí: hlavička požadavku (metoda, URL, kód, IP, doba
odpovědi) žije dlouho, objemné tělo (hlavičky, parametry, payload, odpověď)
se maže dřív.

- Citlivá data (hesla, tokeny, čísla karet) odstraní
  [adt/log-sanitizer](https://github.com/AppsDevTeam/log-sanitizer) ještě před zápisem.
- Hlavička i tělo se zapisují **jednou transakcí** — souběžný odvoz/mazání
  logů nikdy nezastihne osiřelou hlavičku bez těla.
- Časy jsou vždy v UTC s milisekundami, kvůli korelaci s auditním logem
  a jednoznačnosti při přechodu na zimní čas.
- Zapisuje se **vlastním databázovým spojením** (mimo Doctrine EntityManager),
  takže rollback aplikační transakce log nesmaže.
- Selhání logování nikdy neshodí request — zaloguje se přes Tracy a jede se dál.

## Použití

Zaregistruj službu (spojení dostane vlastní, proto bere parametry, ne Connection):

```neon
services:
	- ADT\RequestLogger\RequestLogger(%database%)
```

Projektový security user implementuje minimální rozhraní
`ADT\RequestLogger\SecurityUser` (`isLoggedIn()`, `getId()`).

V base presenteru:

```php
protected function shutdown(Nette\Application\Response $response): void
{
	$this->requestLogger->logRequest($this, $response);
}
```

Loguje se jen požadavek přihlášeného uživatele, nebo požadavek nesoucí API
klíč (`RequestLogger::$apiKeyId`). Anonymní provoz se neloguje.

### Volitelné přepínače

```php
// zalogovat i tělo odpovědi (pozor na objem)
RequestLogger::$logResponse = true;

// požadavek přišel s API klíčem - loguje se i bez přihlášení
RequestLogger::$apiKeyId = $apiKey->getId();
```

### Vlastní sloupce

Projekt si může do `request_log` přidat vlastní sloupce; plní se kdykoliv
během zpracování požadavku:

```php
RequestLogger::addValue('device_id', $deviceId);
RequestLogger::addValue('correlation_id', $correlationId);
```

Systémové sloupce (`created_at`, `method`, `url`, `ip`, `code`,
`response_time`, `identity_id`, `api_key_id`) přepsat nejdou.

## Entity

Balíček dodává rozhraní a traity, entity si deklaruje projekt:

```php
#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
class RequestLog implements \ADT\RequestLogger\Entities\RequestLog
{
	use Identifier;
	use \ADT\RequestLogger\Entities\RequestLogTrait;
}

#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
class RequestLogBody implements \ADT\RequestLogger\Entities\RequestLogBody
{
	use Identifier;
	use \ADT\RequestLogger\Entities\RequestLogBodyTrait;
}
```

**POZOR:** Doctrine čte `#[Index]` jen z entity, na traitě ho ignoruje —
index na `createdAt` si musí deklarovat projekt sám, jinak retenční mazání
projede celou tabulku.

## Testy

```
composer test
```
