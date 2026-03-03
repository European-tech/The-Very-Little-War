# Quality-of-Life Audit Report -- Round 1

**Auditor:** Architecture Agent
**Date:** 2026-03-03
**Scope:** All user-facing PHP pages across 6 player journeys
**Pages read:** index.php, comptetest.php, tutoriel.php, constructions.php, armee.php, attaquer.php, attaque.php, rapports.php, joueur.php, marche.php, alliance.php, allianceadmin.php, ecriremessage.php, forum.php, medailles.php, sinstruire.php, classement.php, compte.php, vacance.php, deconnexion.php, messages.php, messagesenvoyes.php, molecule.php, don.php, inscription.php, guerre.php, listesujets.php, cardsprivate.php

---

## Journey 1 -- New Player (index.php -> comptetest.php -> tutoriel.php -> constructions.php -> armee.php)

### [QOL-R1-001] HIGH index.php -- No password visibility toggle on login form
The login form has a password field with no "show password" toggle. On mobile devices this is especially frustrating because typos are invisible. A simple eye icon toggle would reduce failed login attempts significantly.

### [QOL-R1-002] HIGH index.php -- No "forgot password" link
There is no password recovery flow anywhere. If a player forgets their password, they have no self-service option. This forces them to abandon the account entirely.

### [QOL-R1-003] MEDIUM index.php -- Login error messages are generic and dismissive
When `?att=1` is set, the error says "Un visiteur s'est inscrit il y a moins d'une minute" which is confusing. When URL params are tampered, it says "Ca t'amuse de changer la barre URL ?" which is hostile to legitimate users who may arrive via a bad link.

### [QOL-R1-004] MEDIUM index.php -- No auto-login / "remember me" option
The login form has no "remember me" checkbox. Players must re-enter credentials every session, which is tedious on mobile where the game is primarily used.

### [QOL-R1-005] LOW index.php -- Landing page has no clear call-to-action for returning players
The login form, news, and marketing copy are all mixed together. Returning players must scroll past promotional content to find the login form. The login should be more prominent or sticky.

### [QOL-R1-006] MEDIUM comptetest.php -- Visitor account has no feedback on what credentials are
When a visitor account is created ("Tester" button), the player is redirected to tutoriel.php but is never told their login name or password (VisiteurN / VisiteurN). If they log out, they cannot get back in without guessing.

### [QOL-R1-007] HIGH comptetest.php -- Registration error displayed via JS redirect with error in URL
On registration failure (line 107), the error is passed as a GET parameter to constructions.php: `document.location.href="constructions.php?erreur=..."`. This is confusing because (a) the player is taken to an unrelated page and (b) the error message is in the URL bar. The error should be displayed on the registration form itself.

### [QOL-R1-008] MEDIUM comptetest.php -- No password strength indicator on registration
Neither the visitor upgrade form nor the inscription.php form has any indication of password strength. Players may choose weak passwords with no feedback.

### [QOL-R1-009] HIGH tutoriel.php -- Tutorial missions are only accessible from the menu
New players who skip the initial tutorial redirect have no obvious way to find the tutorial page again. There is no persistent banner or notification pointing them to uncompleted tutorial missions.

### [QOL-R1-010] MEDIUM tutoriel.php -- Mission claim requires a full page reload
Claiming a tutorial reward submits a form that reloads the entire page. An inline confirmation with AJAX or at least a smooth scroll to the claimed mission after reload would feel better.

### [QOL-R1-011] LOW tutoriel.php -- No indication of total energy earned from tutorial
The progress bar shows X/7 missions completed but does not show total energy earned vs. total available (3500 energy). This would motivate completion.

### [QOL-R1-012] HIGH constructions.php -- No tooltip or summary of what each building does at a glance
The construction list uses accordion items that must be expanded one at a time. A new player seeing "Generateur", "Producteur", "Depot" has no idea what they do without expanding each one. A short one-line description visible in the collapsed state would help enormously.

### [QOL-R1-013] HIGH constructions.php -- No indication of current production rates on the main page
The player must mentally calculate or know their production rates. There is no dashboard showing "You produce X energy/h, Y atoms/h." This information is available in the header bar but is hard to read and requires tapping to expand.

### [QOL-R1-014] MEDIUM constructions.php -- Construction queue has no cancel button
Once a construction is started, there is no way to cancel it. If a player accidentally starts the wrong building upgrade, they are stuck waiting. A cancel-with-refund button (minus some penalty) would prevent frustration.

### [QOL-R1-015] MEDIUM constructions.php -- "Assez de ressources le..." date format is hard to parse
When a player cannot afford an upgrade (line 166), the message shows "Assez de ressources le 05/03/2026 a 14h30." This is useful but would benefit from also showing a relative time like "dans 2h30" which is easier to quickly grasp.

### [QOL-R1-016] MEDIUM constructions.php -- Producteur point allocation has no visual preview
When allocating production points among 8 atom types, there is no visual representation of the resulting production rates. Players must guess. A live preview showing "+X hydrogene/h, +Y oxygene/h" per point placed would help decision-making.

### [QOL-R1-017] LOW constructions.php -- Condenseur point allocation is identical UX problem
Same issue as QOL-R1-016 but for condenseur efficiency points. No preview of the effect of each point.

### [QOL-R1-018] MEDIUM constructions.php -- Formation selector has no visual feedback on what each formation does in combat
The three formation options have names and short descriptions but no numerical values. Players cannot see "Formation X gives +20% defense, -10% attack" -- they only see prose descriptions.

### [QOL-R1-019] HIGH armee.php -- No confirmation dialog before deleting a molecule class
Clicking the X (croix.png) immediately submits a form to delete an entire molecule class with no confirmation. This is a destructive action that also cancels ongoing formations. A "Are you sure?" dialog is critical.

### [QOL-R1-020] MEDIUM armee.php -- No molecule stat preview during creation
When creating a new molecule class and entering atom counts, there is a `oninput="javascript:actualiserStats()"` handler but the JS function `actualiserStats` is not defined anywhere in armee.php. This means the real-time stat preview is broken or missing.

### [QOL-R1-021] MEDIUM armee.php -- "Max" button for formation only fills one input at a time
The "Max: X" button sets the molecule count, but there is no "Form Max of all classes" button. Players with multiple classes must click Max and Form for each individually.

### [QOL-R1-022] LOW armee.php -- Neutrino purchase is on the same page as army management
Neutrinos are spies, not soldiers. Having them at the bottom of the army page is confusing for new players. They might better belong in the map/espionage section or at least be visually separated with a header.

### [QOL-R1-023] MEDIUM armee.php -- Formation queue shows no total cost breakdown
The formation queue table shows molecule name, next unit time, and total time. But it does not show the total atoms consumed. After queuing formations, players have no receipt of what resources were spent.

---

## Journey 2 -- Combat (attaquer.php -> attaque.php -> rapports.php -> joueur.php)

### [QOL-R1-024] HIGH attaquer.php -- Map has no zoom controls
The map is a fixed-size div (600x300px) with 80px tiles. On mobile this is very small. There is no pinch-to-zoom, zoom buttons, or scale selector. Players must scroll a tiny viewport to find enemies.

### [QOL-R1-025] HIGH attaquer.php -- Map has no search/find player feature
To find a specific player on the map, you must manually scroll. There is no "Go to player X" or "Go to coordinates X,Y" input on the map page. The only workaround is knowing a player's coordinates from classement.php.

### [QOL-R1-026] MEDIUM attaquer.php -- No player name filter or distance sort on map
All players are displayed as tiles with names. There is no way to filter by alliance, enemy status, or sort by distance. Finding good attack targets requires tedious scrolling.

### [QOL-R1-027] MEDIUM attaquer.php -- Attack form has no "Send All" button
The attack form shows each molecule class with an input field and a clickable max number. But there is no single "Send All Troops" button that fills all classes at once. Players must click max for each class individually.

### [QOL-R1-028] MEDIUM attaquer.php -- Espionage cost in travel time is not shown until the player navigates to the spy form
From the map, clicking a player goes to joueur.php. From there, clicking "Espionner" goes to attaquer.php with type=2. Only then does the player see the travel time. Showing estimated travel time on the joueur.php profile would save a round-trip.

### [QOL-R1-029] LOW attaquer.php -- Beginner protection end date is shown but no countdown timer
Line 187 shows "Fin de la protection des debutants le 05/03/26 a 14h00" as a static date. A live countdown timer would be more engaging, consistent with how construction and attack timers work.

### [QOL-R1-030] MEDIUM attaquer.php -- Incoming attack shows "?" for time with no estimate
When an enemy attacks you (line 248), the time column shows "?". While hiding exact arrival time is a game design choice, even a rough estimate ("arriving soon" / "arriving later") would reduce anxiety.

### [QOL-R1-031] MEDIUM attaque.php -- Attack detail page has no link back to the map centered on target
The attack detail shows the target player link but no quick link to see where they are on the map. The chip shows coordinates but requires manual navigation.

### [QOL-R1-032] HIGH rapports.php -- No report type filter
All reports (combat, espionage, alliance events, pact proposals) are in a single list. There is no way to filter by type. A player with many reports must scroll through everything to find a specific combat report.

### [QOL-R1-033] MEDIUM rapports.php -- No "Mark all as read" button
Reports have read/unread status but there is no way to mark all as read without opening each one. Only "Supprimer tous les rapports" exists, which is destructive.

### [QOL-R1-034] LOW rapports.php -- Report list has no preview of report content
The report list shows title, image, and date. A short preview of the report content (first 50 characters) would help players identify important reports without clicking each one.

### [QOL-R1-035] MEDIUM rapports.php -- No "Delete read reports" option
Players can only delete all reports or one at a time. There is no "delete all read reports" button, which is the most common cleanup action.

### [QOL-R1-036] MEDIUM joueur.php -- Player profile shows no army strength indicator
The player profile shows rank, points, victories, and position. But there is no indicator of military strength (total molecules, army power estimate). This information is critical before deciding to attack, yet requires spending neutrinos on espionage to discover.

### [QOL-R1-037] LOW joueur.php -- Player profile shows no "last active" timestamp
There is a statut() function showing "Inactif" but no indication of when the player was last online. Knowing "last seen 3 days ago" vs. "last seen 30 minutes ago" helps decide if attacking is wise.

### [QOL-R1-038] MEDIUM joueur.php -- No quick-link to spy directly from profile without navigating to a separate form
The profile has an "Espionner" link that navigates to attaquer.php?type=2, which then shows a form requiring the player to select neutrino count and submit. A one-click "Spy with 1 neutrino" quick action would streamline this.

---

## Journey 3 -- Economy (marche.php -> constructions.php -> resource display)

### [QOL-R1-039] HIGH marche.php -- No "Max Buy" or "Max Sell" button
The market forms require manual entry of the quantity. There is no button to buy/sell the maximum affordable amount. Players must mentally calculate their budget, then type a number.

### [QOL-R1-040] MEDIUM marche.php -- Market price chart has no time axis labels
The Google Charts price graph has empty string labels for the X axis (line 587: `['"",' ...`]`). Players see the curve but cannot tell if a price spike was 1 hour ago or 3 days ago.

### [QOL-R1-041] MEDIUM marche.php -- No price history for each resource individually
The chart shows all 8 resources on one graph with 1000 data points. It is visually cluttered. Clicking a resource to isolate its price history would improve readability.

### [QOL-R1-042] LOW marche.php -- Sell form shows available quantity in parentheses in the dropdown but not as a live number next to the input
When selling, the resource count is shown as "Hydrogene (1.2k)" in the select dropdown. But once selected, there is no reminder of how much you have next to the quantity input. The buy form has this issue too.

### [QOL-R1-043] MEDIUM marche.php -- Resource sending form has no recipient autocomplete
The "Destinataire" field for sending resources (line 426) is a plain text input. There is no autocomplete or player search. Players must type the exact login name with correct capitalization.

### [QOL-R1-044] MEDIUM marche.php -- No confirmation before sending resources to another player
Sending resources is an irreversible action. There is no "Are you sure you want to send X to Player Y?" confirmation. A mistyped recipient name that happens to exist would result in sending resources to the wrong player.

### [QOL-R1-045] LOW marche.php -- The 5% sell tax is only mentioned in the success message, not in the form
The sell form has no visible indication that a 5% fee applies. Players discover this only after completing a sale. The sell section should clearly state "5% de frais de vente" upfront.

### [QOL-R1-046] MEDIUM marche.php -- Pending shipments table has no details of what was sent
The shipment table shows type (arrow icon), player name, and countdown timer. But it does not show what resources are in transit. Players forget what they sent or need to check.

---

## Journey 4 -- Social (alliance.php -> allianceadmin.php -> ecriremessage.php -> forum.php)

### [QOL-R1-047] HIGH alliance.php -- No alliance search/browse feature
Players without an alliance see only "Create an alliance" and invitations. There is no way to browse existing alliances, see their descriptions, or request to join. They must find alliance leaders through other channels (forum, messages) which creates a high barrier.

### [QOL-R1-048] MEDIUM alliance.php -- Quitting an alliance has no confirmation dialog
Clicking the "Quitter" icon immediately submits a form to leave the alliance. There is no confirmation. Accidental clicks or mis-taps on mobile can remove a player from their alliance.

### [QOL-R1-049] MEDIUM alliance.php -- Alliance energy donation (don.php) has no "Donate All" or percentage buttons
The donation form is a plain text input. Quick buttons like "10%", "25%", "50%", "All" would make the common donation workflow much faster.

### [QOL-R1-050] MEDIUM alliance.php -- Duplicateur and research upgrades have no progress bar toward next level
The alliance buildings show current level and cost for next level, but no visual progress bar showing how close the alliance energy pool is to affording the upgrade. A "X% funded" indicator would help coordinate donations.

### [QOL-R1-051] LOW alliance.php -- Alliance member list has no online/offline indicator
The member table shows points, attacks, defense, etc. but not whether each member is currently online or recently active. This information helps alliance leaders coordinate.

### [QOL-R1-052] MEDIUM allianceadmin.php -- Deleting alliance has no two-step confirmation
The "Supprimer l'equipe" button directly submits the form. There is no "Are you really sure?" confirmation. This is the most destructive action in the entire alliance system.

### [QOL-R1-053] MEDIUM allianceadmin.php -- Invite player field uses hidden input + AJAX autocomplete but UX is unclear
The invite field (line 376) uses a hidden input with an autocomplete labeled "labelInviter" but the actual visible text input is not obvious. The player sees "Nom du joueur" as helper text but the interaction pattern is non-standard.

### [QOL-R1-054] LOW allianceadmin.php -- Grade deletion uses hidden input that only captures the last grade
The grade list table (line 431) has a single hidden input `name="joueurGrade"` inside a loop. Each row overwrites the previous value. If a leader clicks delete on a specific row, it may delete a different grade depending on form scope. This is both a bug and a UX issue.

### [QOL-R1-055] MEDIUM ecriremessage.php -- No character count or message length limit indicator
The message textarea has no character counter. Players do not know if there is a maximum length or how close they are to it. Long messages might be silently truncated.

### [QOL-R1-056] LOW ecriremessage.php -- Sent messages redirect to messages.php (inbox) with no success banner
After successfully sending a message (line 33-34), the player is redirected to messages.php. The $information variable is set but lost during the redirect because it uses header('Location'). The player gets no confirmation that the message was sent.

### [QOL-R1-057] MEDIUM forum.php -- Forum has no search functionality
There is no way to search forum posts by keyword. Players must browse through pages of topics manually. A search bar would make the forum much more useful.

### [QOL-R1-058] LOW forum.php -- Forum category list shows topic count and message count but not "last post" info
The forum index shows number of topics and total messages per category but not the date/author of the most recent post. This makes it hard to see which forums are active.

### [QOL-R1-059] LOW listesujets.php -- Topic list has no reply count column
The topic list shows status, title, author, and date. There is no column for number of replies. Popular topics are indistinguishable from empty ones at a glance.

---

## Journey 5 -- Progression (medailles.php -> sinstruire.php -> classement.php -> prestige)

### [QOL-R1-060] HIGH medailles.php -- No progress bar toward next medal tier
Each medal shows "current value / next threshold" as text (e.g., "45/100"). A visual progress bar would make advancement feel more tangible and motivating. This is one of the most impactful missing features for player retention.

### [QOL-R1-061] MEDIUM medailles.php -- Medal bonuses are hard to compare
The medal list shows current bonus and next bonus in accordion dropdowns. A summary table of all active bonuses would help players understand their total bonuses at a glance.

### [QOL-R1-062] LOW medailles.php -- Viewing another player's medals gives no comparison to your own
When viewing medailles.php?login=OtherPlayer, there is no side-by-side comparison with your own medals. This reduces the social/competitive aspect of the medal system.

### [QOL-R1-063] MEDIUM sinstruire.php -- Course content is entirely static HTML with no interactivity
The chemistry courses are long walls of text with no interactive elements, quizzes, or checkpoints. Given that this is a game, adding simple quiz questions or "did you know?" callouts would increase engagement.

### [QOL-R1-064] LOW sinstruire.php -- No way to know which courses you have already read
The course selector is a dropdown with no read/unread indicators. Players returning to sinstruire.php cannot tell which courses they have already visited.

### [QOL-R1-065] MEDIUM classement.php -- Classement has no "jump to my rank" button when browsing different sort categories
The ranking auto-scrolls to the player's page when first loaded, but when switching to attack/defense/pillage sorting, the page defaults to the top and the player must manually navigate to find themselves.

### [QOL-R1-066] LOW classement.php -- Player search in classement only works for exact names
The search requires the exact player login. There is no partial/fuzzy search. Typing "Guo" will not find "Guortates." This is frustrating when you only partially remember a name.

### [QOL-R1-067] MEDIUM classement.php -- No "previous season" or historical ranking data
The classement only shows current standings. There is no way to view past season results, winner history, or your own historical performance. This removes long-term engagement context.

### [QOL-R1-068] LOW classement.php -- War history ranking (sub=2) has no search/filter
The war history page shows wars sorted by total losses. There is no way to search for wars involving a specific alliance.

### [QOL-R1-069] MEDIUM classement.php -- Season end countdown is nowhere visible
The game has monthly resets but there is no visible countdown to season end. Players must calculate from the current date. A prominent "Season ends in X days" banner would create urgency.

---

## Journey 6 -- Account (compte.php -> vacance.php -> deconnexion.php)

### [QOL-R1-070] MEDIUM compte.php -- Profile photo max dimension (150x150) error message comes after upload attempt
The 150x150 pixel limit is mentioned only in the section header text. If a player uploads a larger image, they get an error after the upload completes. Client-side validation or automatic resizing would save time.

### [QOL-R1-071] LOW compte.php -- Vacation mode date picker uses a custom calendar but with no minimum date enforcement client-side
The vacation date picker (calVacs) allows selecting dates, but the 3-day minimum is only enforced server-side. The calendar should not allow selecting dates less than 3 days from now.

### [QOL-R1-072] MEDIUM compte.php -- No notification preferences
There is no way to opt in/out of email notifications for attacks, messages, season events, etc. Players may want to receive email alerts for incoming attacks but not for forum replies.

### [QOL-R1-073] LOW compte.php -- Account deletion has a one-week waiting period with no countdown
The message says "Le compte ne peut etre supprime qu'au bout d'une semaine" but does not show when the account was created or when deletion becomes available. A specific date would be clearer.

### [QOL-R1-074] MEDIUM deconnexion.php -- Logout has no confirmation
Clicking logout immediately destroys the session and redirects to index.php. On mobile where accidental taps are common, a brief confirmation or undo option would prevent frustration.

### [QOL-R1-075] LOW vacance.php -- Vacation page shows only a static message with no return date
When a player is on vacation and visits a game page, they see "Vous etes en vacances et ne pouvez pas acceder a cette page." But the message does not show when vacation ends or provide a link to end it early.

---

## Cross-Cutting QOL Issues

### [QOL-R1-076] HIGH all pages -- No notification badge or count for unread messages/reports
The game menu has links to Messages and Rapports but no unread count badges. Players must click into each section to check for new content. A red badge with unread count (like "Messages (3)") on the navigation menu would dramatically improve awareness.

### [QOL-R1-077] HIGH all pages -- No sound or visual notification for incoming attacks
When an enemy launches an attack, the only indication is a shield icon in the attacks table on attaquer.php. There is no push notification, alert banner, or sound. Players who are browsing other pages may not notice they are under attack until it is too late.

### [QOL-R1-078] MEDIUM all pages -- Timer auto-refresh reloads the entire page
When a construction finishes, formation completes, or attack arrives, the JavaScript timer triggers `document.location.href="..."` which fully reloads the page. This causes a jarring flash, loss of scroll position, and unnecessary server load. AJAX-based updates would be smoother.

### [QOL-R1-079] MEDIUM all pages -- No dark mode support
The game uses a light theme with white backgrounds. Many players (especially on mobile at night) would benefit from a dark mode toggle. The Framework7 CSS supports theming but it is not exposed to users.

### [QOL-R1-080] HIGH all pages -- No resource production summary dashboard
There is no single page or widget showing a comprehensive overview of the player's economy: energy production, energy consumption, net energy, each atom's production rate, storage capacity utilization, and estimated time to fill storage. This forces players to visit multiple pages and do mental math.

### [QOL-R1-081] MEDIUM all pages -- No keyboard shortcuts for common actions
There are no keyboard shortcuts for navigating between pages (e.g., C for constructions, A for army, M for map). Power users on desktop must always use the mouse/menu.

### [QOL-R1-082] MEDIUM all pages -- Page titles in browser tab are all "The Very Little War"
Most pages do not set a unique `<title>` tag. When multiple tabs are open, they all show the same title, making it impossible to identify which tab is which.

### [QOL-R1-083] LOW all pages -- Error messages use inconsistent tone
Some errors are professional ("Vous n'avez pas assez de ressources"), while others are dismissive or hostile ("T'y as cru ?", "Ca t'amuse de changer la barre URL ?", "Stop ca petit troll !"). A consistent, respectful tone improves user experience.

### [QOL-R1-084] MEDIUM all pages -- No loading indicator for form submissions
Form submissions (buy, sell, attack, construct) have no loading spinner or button disabled state. Players may double-click, causing duplicate submissions. The submit button should show a loading state and disable itself on click.

### [QOL-R1-085] HIGH messages.php -- Sent messages page has no pagination
messagesenvoyes.php loads ALL sent messages with no pagination (line 10: no LIMIT clause). For active players with hundreds of sent messages, this page will be extremely slow and may time out.

### [QOL-R1-086] MEDIUM messages.php -- No "Sent" folder link from compose page
After writing a message in ecriremessage.php, there is no link to view sent messages. The "Envoyes" link is only available from the messages inbox footer.

### [QOL-R1-087] MEDIUM messages.php -- No multi-select for bulk message deletion
Messages can only be deleted one at a time or all at once. There is no checkbox-based multi-select for deleting a batch of specific messages.

### [QOL-R1-088] LOW all pages -- No breadcrumb navigation
Pages do not show breadcrumb trails. When deep in a flow (e.g., Alliance > Admin > Grades), there is no visual indicator of where you are in the navigation hierarchy.

### [QOL-R1-089] MEDIUM molecule.php -- Molecule stats page has no comparison view
The molecule stats page shows one class at a time. There is no way to compare two molecule classes side by side to evaluate trade-offs between attack-oriented vs. defense-oriented compositions.

### [QOL-R1-090] HIGH armee.php / constructions.php -- No global "what should I do next?" guidance after tutorial
Once the tutorial is complete, there is no ongoing guidance system. New players who finish the 7-mission tutorial are left without direction. A persistent "suggested next action" widget (e.g., "Your generateur is low -- upgrade it!") would improve retention.

### [QOL-R1-091] MEDIUM constructions.php -- Construction timer shows end time but not start time
The construction queue shows "Temps restant" and "Fin" (end time) but not when the construction was started or what percentage is complete. A progress bar per construction would feel better.

### [QOL-R1-092] LOW inscription.php -- Registration form has a "Generate" button for login but no explanation
The "Generer" button calls a JavaScript `generate()` function to create a random login. This is not explained and may confuse users who expect to choose their own name. A tooltip would help.

### [QOL-R1-093] MEDIUM all pages -- No in-game changelog or "what's new" notification
When game updates are deployed, there is no way to notify players about new features or balance changes. The news card on the homepage shows admin-posted news but it is easy to miss and lacks versioning.

### [QOL-R1-094] LOW guerre.php -- War detail page has no link to involved players
The war detail shows alliance tags and loss percentages but no links to individual battle reports or participating players. This reduces the narrative quality of wars.

### [QOL-R1-095] HIGH attaquer.php -- Map does not show player names on mobile without zooming
The map tiles are 80x80px with player names overlaid at the top in a small, semi-transparent black bar. On mobile screens, names are often truncated or illegible. A tap-to-see-details interaction would be more mobile-friendly.

### [QOL-R1-096] MEDIUM all pages -- No "time since last resource update" indicator
Resources are calculated on each page load via updateRessources(). But there is no visual indication of how stale the displayed resource counts are. If a player stays on a page for 30 minutes without navigating, the displayed resources are outdated.

### [QOL-R1-097] MEDIUM constructions.php -- No quick comparison of current level vs. cost for all buildings
Players must expand each building accordion individually to see costs. A compact "upgrades overview" table showing all buildings, their current levels, and upgrade costs side by side would help prioritize.

### [QOL-R1-098] LOW all pages -- Pagination is minimal and not mobile-friendly
The pagination across rapports.php, messages.php, classement.php, and listesujets.php uses plain text links with no styling. Touch targets are small. Modern pagination with larger buttons and "First/Last" controls would improve mobile usability.

### [QOL-R1-099] MEDIUM marche.php -- No trade history log
After buying or selling on the market, there is no personal trade history. Players cannot review past transactions to understand their spending patterns or verify recent trades.

### [QOL-R1-100] LOW all pages -- Success messages ($information) disappear on next navigation
Success/error messages are shown via PHP variables that exist only for the current page load. If the player navigates away before reading the message, it is lost. Flash messages stored in session would persist across one redirect.

---

## Summary Statistics

| Priority | Count |
|----------|-------|
| HIGH     | 16    |
| MEDIUM   | 55    |
| LOW      | 29    |
| **Total**| **100** |

### Top 10 Highest-Impact Recommendations

1. **[QOL-R1-076]** Unread message/report badges in navigation menu
2. **[QOL-R1-080]** Resource production summary dashboard
3. **[QOL-R1-060]** Progress bars on medal advancement
4. **[QOL-R1-024]** Map zoom controls for mobile
5. **[QOL-R1-019]** Confirmation dialog before deleting molecule classes
6. **[QOL-R1-032]** Report type filtering
7. **[QOL-R1-047]** Alliance search/browse feature
8. **[QOL-R1-039]** Max buy/sell buttons on market
9. **[QOL-R1-090]** Post-tutorial guidance system
10. **[QOL-R1-002]** Password recovery flow
