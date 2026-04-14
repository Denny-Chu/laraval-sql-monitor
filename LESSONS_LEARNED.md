# Laravel SQL Monitor — Lessons Learned

> 本文件記錄專案開發過程中遇到的 bug、設計缺陷及修正教訓，作為後續持續改進的參考。
> 最後更新：2026-04-14

---

## 目錄

1. [無限迴圈：Event Listener 自我觸發](#1-無限迴圈event-listener-自我觸發)
2. [Event 與 Broadcast 職責耦合導致連鎖失敗](#2-event-與-broadcast-職責耦合導致連鎖失敗)
3. [Config 設計不完整導致 Storage Driver 無法切換](#3-config-設計不完整導致-storage-driver-無法切換)
4. [Constructor 中觸發 DB 操作造成容器遞迴](#4-constructor-中觸發-db-操作造成容器遞迴)
5. [靜態分析的邊界案例：型別假設與 Facade 誤判](#5-靜態分析的邊界案例型別假設與-facade-誤判)
6. [終端方法偵測邏輯錯誤導致誤報](#6-終端方法偵測邏輯錯誤導致誤報)
7. [檔案截斷：未提交變更中的程式碼遺失](#7-檔案截斷未提交變更中的程式碼遺失)
8. [Composer Metadata 與實際功能不一致](#8-composer-metadata-與實際功能不一致)
9. [Commit 訊息品質不足](#9-commit-訊息品質不足)

---

## 1. 無限迴圈：Event Listener 自我觸發

**Commit:** `471656e` — fix: infinite loop listener and fail connection

**問題描述：**
當 `storage.driver = database` 且使用與應用程式相同的 MySQL 連線時，`DatabaseQueryStore::persist()` 執行 INSERT 會再次觸發 `QueryExecuted` 事件，導致 `QueryListener::handle()` 無限遞迴，最終 stack overflow。

**根因：**
原始設計只考慮了 SQLite driver（使用獨立的 `sql_monitor` 連線名），透過硬編碼 `$event->connectionName === 'sql_monitor'` 來跳過。當新增 database driver 後，此過濾邏輯不再適用。

**修正方式：**
- 在 `QueryListener` 加入 `private static bool $handling = false` re-entrancy guard
- 新增 `excluded_connections` 黑名單配置（優先於白名單）
- 新增 `connections` 白名單配置（空陣列 = 監控所有）
- 整個 handle() 包裹在 `try/finally` 中確保 flag 必定重置

**教訓：**
- **監控類套件必須從設計初期考慮「自我監控」問題。** 任何會產生 DB 查詢的 storage 實作，都可能觸發被監控的事件。
- **連線過濾不能只靠硬編碼名稱**，應提供 config 層級的白/黑名單機制。
- **re-entrancy guard 是必要的防線**，即使有連線過濾，static flag 作為最後一道保護仍不可少。
- **try/finally 保護 flag 重置**：任何在 handling 內的例外都不應導致 flag 卡住。

---

## 2. Event 與 Broadcast 職責耦合導致連鎖失敗

**Commit:** `360169a` — fix:bug

**問題描述：**
`QueryCapturedEvent`、`SlowQueryDetectedEvent`、`N1PatternDetectedEvent` 三個 Event 類別都直接實作了 `ShouldBroadcastNow` 介面。當 broadcast driver（如 Pusher）不可用或拋出例外時，`event()` 呼叫會直接失敗，而這發生在 `QueryListener::handle()` 內部，導致：
1. re-entrancy guard (`$handling` flag) 可能未被正確重置
2. 整條 query 監控鏈中斷

**根因：**
Event 同時承擔「資料容器」和「廣播通道定義」兩個職責，違反單一職責原則。

**修正方式：**
- Event 類別移除 `ShouldBroadcastNow`，改為純資料容器
- 廣播邏輯統一收歸 `LiveQueryMonitor`，透過 `Broadcast` Facade 安全推送
- `LiveQueryMonitor` 內部用 try/catch 隔離廣播失敗

**教訓：**
- **Event 應保持為純資料容器**，不應直接實作 broadcast 介面。副作用（如網路 I/O）應由專門的服務類處理。
- **在 listener 內部呼叫可能失敗的外部服務（Pusher, Redis 等），必須用 try/catch 隔離**，否則會破壞核心監控功能。
- **職責分離不只是「好習慣」**，在 monitoring/observability 類套件中，它直接影響系統穩定性。

---

## 3. Config 設計不完整導致 Storage Driver 無法切換

**Commit:** `45cb979` — fix: 修正 config 無法正確讀取 db

**問題描述：**
`sql-monitor.php` config 中 storage 區塊缺少 `connection` 和 `table` 欄位，`SqlMonitorServiceProvider` 也沒有 database driver 的建構邏輯，導致 `storage.driver = 'database'` 形同虛設。

**根因：**
初版只實作了 SQLite driver，database driver 是後加的功能但 config 和 service provider 沒有同步更新。

**修正方式：**
- 在 config 加入 `connection` 和 `table` 欄位
- ServiceProvider 中新增 `DatabaseQueryStore` 的建構分支
- 對不支援的 driver 拋出 `MonitorException`

**教訓：**
- **新增 driver/strategy 時，必須同步更新：config schema → ServiceProvider binding → README 文檔。** 缺一不可。
- **使用 env() helper 讓設定值可從 .env 覆寫**，而非只能改 config 檔。
- **對無效的 driver 值應拋出明確例外**，而非 fallback 到預設值（這會隱藏配置錯誤）。

---

## 4. Constructor 中觸發 DB 操作造成容器遞迴

**涉及：** `DatabaseQueryStore` 的 `ensureTableExists()` 延遲呼叫設計

**問題描述：**
最初 `DatabaseQueryStore` 在 constructor 中直接呼叫 `ensureTableExists()`。由於 ServiceProvider 用 singleton 綁定，建構發生在容器解析期間，此時 DB 操作會觸發 `QueryExecuted` 事件，而 listener 又會嘗試解析 `QueryStoreInterface`，形成容器遞迴。

**修正方式：**
- 改用 `$tableEnsured` boolean flag 延遲到第一次 `db()` 呼叫時才建表
- **先設 flag 再呼叫 `ensureTableExists()`**，避免建表 SQL 自身再次進入

**教訓：**
- **Singleton 的 constructor 中不應執行會觸發事件的操作。** 特別是在 monitoring 類套件中，任何 DB 操作都可能觸發被監控的事件。
- **延遲初始化（lazy initialization）是更安全的模式。** 搭配「先設 flag 再執行」確保不會遞迴。

---

## 5. 靜態分析的邊界案例：型別假設與 Facade 誤判

**Commit:** `9d5955f` — fix: edge cases

**問題描述（多個子問題）：**

### 5a. 非 Eloquent Facade 被誤判為查詢
`Cache::has('key')`、`Event::dispatch()` 等呼叫被 `QueryChainExtractor` 誤認為 Eloquent 查詢，因為 `has()`、`find()` 等方法名與 Eloquent 靜態方法重疊。

**修正：** 新增 `NON_ELOQUENT_CLASSES` 排除清單，包含常見 Laravel Facade（短名稱 + 完整命名空間）。

### 5b. `selects` 陣列中的非字串元素導致 `str_ends_with()` 報錯
`hasSelectStar()` 方法對 `$this->selects` 的每個元素呼叫 `str_ends_with()`，但陣列中可能包含 `DB::raw()` 回傳的物件或 null。

**修正：** 加入 `is_string($col)` 型別檢查。

### 5c. `stringifyArg()` 遞迴處理不完整
陣列參數的 `implode` 直接串接子元素，未遞迴呼叫 `stringifyArg()`，嵌套陣列會被序列化為 `Array to string conversion`。

**修正：** 改用 `array_map([$this, 'stringifyArg'], $arg)` 遞迴處理。

### 5d. WHERE 條件的 column 解析過於寬鬆
`whereRaw()`、`whereExists()` 等方法的第一個參數不是欄位名，但都被當作 column 記錄，導致後續的 index 檢查誤判。

**修正：** 新增 `COLUMN_WHERE_METHODS` 清單，只有明確以欄位名作為第一參數的方法才記錄 column。Closure 和變數參考也被排除。

**教訓：**
- **AST 分析不能假設所有同名方法的語意相同。** `Cache::has()` 和 `User::has()` 是完全不同的東西。
- **處理使用者程式碼時，必須假設任何類型都可能出現。** 永遠做型別檢查，不要假設陣列內容的型別。
- **遞迴結構必須遞迴處理。** 如果一個函式處理「值」，它應該能處理嵌套值。
- **Query Builder 的 where 系列方法語意差異很大**，不能一概而論。需要細分哪些方法真正接受 column 參數。

---

## 6. 終端方法偵測邏輯錯誤導致誤報

**涉及：** 未提交變更中 `QueryChainExtractor` 的 terminalMethod 偵測重構

**問題描述：**
原始邏輯在整條方法鏈解析完後，只看最外層 `MethodCall` 的方法名是否屬於 `TERMINAL_METHODS`。但 PhpParser 解析鏈式呼叫時，最外層節點實際上是鏈的「最後一步」，這導致：
1. Eloquent 的根方法（如 `whereHas`）不被 `classifyCall` 處理，遺失 WHERE 資訊
2. `->get(['col1', 'col2'])` 中的 column 參數沒有被記錄到 `selects`
3. `find()`, `paginate()` 等帶有 column 參數的方法沒有被正確解析

**修正方式：**
- Eloquent 根方法在建立 CallSite 後立即呼叫 `classifyCall()` 處理
- 移除尾部的「最外層方法」特殊處理，改為在 `classifyCall()` 內統一設定 `terminalMethod`
- 新增 `get()`, `first()`, `find()`, `paginate()` 等方法的 column 參數解析
- 新增 `recordSelectArguments()` 方法統一處理 select 欄位記錄

**教訓：**
- **AST 鏈式呼叫的解析順序必須完全理解。** PhpParser 中 `A->B->C()` 的 AST 結構是 `MethodCall(MethodCall(A, B), C)`，最外層是 C 不是 A。
- **方法鏈中的每個節點都應統一走同一套分類邏輯**，不應對根方法、中間方法、終端方法用不同的處理路徑，否則容易遺漏。
- **Laravel 的 `get(['columns'])`、`find($id, ['columns'])`、`paginate($perPage, ['columns'])` 等方法都能指定 SELECT 欄位**，靜態分析必須完整涵蓋。

---

## 7. 檔案截斷：未提交變更中的程式碼遺失

**涉及：** 未提交的 `QueryChainExtractor.php` 和 `CallSiteAnalyser.php`

**問題描述：**
兩個檔案在未提交的修改中，末尾出現大量程式碼被刪除但未被替換的情況。`QueryChainExtractor.php` 缺少 `extractValue()` 的多個分支（ArrowFunction, Variable, ClassConstFetch）、`extractArray()`、`resolveJoinType()`、`resolveOperator()`、`resolveWhereColumn()`、`resolveWhereValue()`、`stringifyJoinCondition()` 等方法。`CallSiteAnalyser.php` 缺少 `normalizeTableName()`、`isWriteOperation()`、`isBulkTerminal()`、`issue()` 等方法。

**影響：** 這些檔案在目前狀態下無法通過編譯。

**教訓：**
- **大幅重構時應分步提交**，每一步都保持可編譯狀態。
- **每次修改後應執行 `php -l` 或 PHPStan 檢查**，確認語法完整。
- **使用 IDE 的「安全刪除」功能**，確保不會意外刪除仍被引用的方法。
- **重構中途需要暫停時，使用 `git stash` 保存工作，而非留下半完成的狀態。**

---

## 8. Composer Metadata 與實際功能不一致

**Commit:** `10ef0fe` — fix: keyword description

**問題描述：**
`composer.json` 的 keywords 陣列包含 `"n+1"`，但套件的 N+1 偵測功能是自動內建的分析模組，並非獨立可搜尋的關鍵字特性。此外 description 欄位也需要更精確地反映套件定位。

**修正方式：**
移除 `"n+1"` keyword，保留更準確的描述詞。

**教訓：**
- **Composer keywords 應反映套件的主要用途和搜尋情境**，而非內部實作細節。
- **定期檢視 metadata 是否與實際功能對齊**，特別是在功能範圍變動後。

---

## 9. Commit 訊息品質不足

**涉及：** 多個 commit

**問題描述：**
- `360169a` 的訊息是 `fix:bug` — 完全沒有描述修了什麼
- `9d5955f` 的訊息是 `fix: edge cases` — 模糊不清，涵蓋了至少 4 個獨立問題
- `471656e` 的訊息末尾有多餘的引號

**教訓：**
- **Commit 訊息應明確描述「修了什麼」和「為什麼」**，例如：`fix: remove ShouldBroadcastNow from Events to prevent broadcast failures breaking QueryListener`
- **一個 commit 應該只修一個問題。** edge cases commit 涵蓋了 Facade 誤判、型別檢查、遞迴處理、WHERE column 解析四個不同問題，應該拆成四個 commit。
- **使用 conventional commits 格式時注意語法**，冒號後加空格、不要多餘字元。

---

## 通用設計原則摘要

基於以上所有教訓，歸納出本專案應遵循的設計原則：

1. **自我監控隔離原則：** 監控套件的 storage 操作必須與被監控的操作完全隔離，透過獨立連線、re-entrancy guard、連線黑名單三層防護。

2. **Event 純資料原則：** Event 類別只承載資料，所有副作用（廣播、日誌、通知）由專門的 Service 處理，且必須 try/catch 隔離。

3. **延遲初始化原則：** Singleton 的 constructor 不應有任何可能觸發事件的操作。DB 操作延遲到首次使用時執行。

4. **防禦性型別檢查原則：** 分析使用者程式碼時，永遠不假設資料的型別。每個存取都應有型別檢查。

5. **AST 一致性處理原則：** 方法鏈中的每個節點（根方法、中間方法、終端方法）應走同一套分類邏輯。

6. **Config 完整性原則：** 新增 driver/strategy 時，config schema、ServiceProvider、README 必須同步更新。

7. **原子性提交原則：** 每個 commit 只解決一個問題，訊息明確描述 what 和 why，重構過程中每步都保持可編譯。

---

## 待處理項目（Action Items）

- [ ] **修復 `QueryChainExtractor.php` 檔案截斷** — 恢復被刪除的輔助方法
- [ ] **修復 `CallSiteAnalyser.php` 檔案截斷** — 恢復被刪除的 private 方法
- [ ] **執行 `php -l` 確認所有檔案語法正確**
- [ ] **執行測試套件確認無回歸**
- [ ] **將 `NON_ELOQUENT_CLASSES` 清單改為可配置**，允許使用者擴充
- [ ] **考慮為 `DatabaseQueryStore` 加入批量 INSERT** 提升寫入效能
- [ ] **補充整合測試**：測試 database driver 在真實 MySQL 連線下的行為
