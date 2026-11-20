# IUC Syd – Medlemsportal (WordPress)

Detta repository innehåller **endast vår egenutvecklade kod** (egna plugins och teman) för IUC Syds WordPress-portal. WordPress-kärnan, `wp-config.php`, uppladdad media (`uploads`) och färdiga tredjepartsplugins ignoreras via `.gitignore` och hanteras lokalt via **LocalWP**.

## Projektstruktur & namnkonvention

Git spårar bara:
- `.gitignore` och `README.md`
- Egna plugins i `wp-content/plugins/iuc-*` (t.ex. `wp-content/plugins/iuc-syd-feature`)
- Egna teman / child themes i `wp-content/themes/iuc-*`

> **Viktigt:** Alla nya egna plugins och teman måste börja med prefixet `iuc-` för att automatiskt plockas upp av Git.

---

## Kom igång lokalt (LocalWP)

### Alternativ A: Skapa en ny, ren LocalWP-sajt (rekommenderas)
1. Skapa en ny sajt i **LocalWP** (t.ex. `iuc-syd`) med standardinställningen **Preferred**.
2. Högerklicka på sajten i LocalWP och välj **Open site shell** (du hamnar direkt i `app/public`).
3. Koppla mappen till GitHub:
   ```bash
   git init
   git remote add origin [https://github.com/Andsansa/iuc-syd-wordpress.git](https://github.com/Andsansa/iuc-syd-wordpress.git)
   git fetch origin
   git checkout -b main origin/main
   ```
4. Kör `git status` — står det `nothing to commit, working tree clean` är allt rätt kopplat.

### Alternativ B: Koppla om en befintlig LocalWP-sajt
> **Obs!** Kör *inte* `git pull` i en gammal koppling som sattes upp innan repot städades — då kan Git radera dina lokala WordPress-filer.

1. **Spara undan eventuell opushad kod** (t.ex. i `wp-content/plugins/iuc-syd-feature`) till Skrivbordet.
2. Öppna sajtens `app/public`-mapp i Utforskaren/Finder och visa dolda filer (`Visa → Dolda objekt` på Windows, `Cmd + Shift + .` på Mac).
3. **Radera den dolda mappen `.git`** i `app/public` (påverkar inte WordPress eller databasen).
4. Radera eventuell gammal `.gitignore` i `app/public` samt eventuell gammal kopia av `wp-content/plugins/iuc-syd-feature` (den hämtas ner på nytt i nästa steg).
5. Högerklicka på sajten i LocalWP, välj **Open site shell** och kör:
   ```bash
   git init
   git remote add origin [https://github.com/Andsansa/iuc-syd-wordpress.git](https://github.com/Andsansa/iuc-syd-wordpress.git)
   git fetch origin
   git checkout -b main origin/main
   ```
6. Kör `git status` för att bekräfta `working tree clean`.

---

## Färdiga plugins (installeras lokalt)

Färdiga plugins versionshanteras inte i Git. Installera dem via **Open site shell** i LocalWP så att alla i teamet kör samma uppsättning:

```bash
wp plugin install buddypress --activate
```

Öppna sedan **WP Admin → Tillägg (Plugins)** och aktivera även vårt eget plugin **IUC Syd Portal Features**.

> **När vi lägger till ett nytt tredjepartsplugin:** Lägg in det i WP-CLI-kommandot ovan (och skriv i teamchatten) så att alla installerar samma plugin lokalt.

---

## Arbetsflöde i teamet

### 1. Branches och Pull Requests
Vi kodar aldrig direkt på `main`. För varje ny uppgift eller buggfix:
1. Hämta senaste koden:
   ```bash
   git checkout main
   git pull origin main
   ```
2. Skapa en egen branch:
   ```bash
   git checkout -b feature/kort-beskrivning
   ```
3. Koda, testa i LocalWP, committa och pusha din branch:
   ```bash
   git add .
   git commit -m "Tydlig beskrivning av ändringen"
   git push -u origin feature/kort-beskrivning
   ```
4. Öppna en **Pull Request** på GitHub och låt minst en lagkamrat granska innan merge till `main`.

### 2. Redigera aldrig färdiga plugins direkt
Ändringar direkt i t.ex. BuddyPress skrivs över vid uppdateringar och sparas inte i Git. All anpassning sker i våra egna `iuc-*`-plugins via WordPress/BuddyPress hooks och filters.

### 3. Kod vs. databas (WP Admin)
- **Git synkar bara kodfiler** (PHP, CSS, JS).
- **Innehåll och inställningar i WP Admin** (sidor, menyer, testanvändare, plugin-inställningar) ligger i din lokala databas och följer *inte* med i Git. Vid större ändringar i WP Admin som hela teamet behöver lokalt dokumenterar vi inställningarna eller exporterar/importerar en gemensam sajtbackup via LocalWP.