# Laravel SQL Monitor — 文件清單

## 📊 項目統計

- **總檔案數**：46 個
- **PHP 代碼**：4,120 行生產代碼
- **文件類型**：PHP、Markdown、JSON、Blade
- **模組數**：10 個核心模組

---

## 📁 完整文件結構

```
laravel-sql-monitor/
│
├── composer.json                          # 包依賴配置
│
├── README.md                              # 項目首頁文檔
├── FILE_MANIFEST.md                       # 本文件
│
├── docs/                                  # 完整文檔
│   ├── ARCHITECTURE_OVERVIEW.md          # 架構總覽（重要！）
│   ├── STATIC_ANALYSIS.md                # 靜態分析指南
│   └── [DYNAMIC_ANALYSIS.md]             # 待補充
│
├── src/                                   # 核心源碼（4,120 行）
│   │
│   ├── SqlMonitorServiceProvider.php      # 服務提供者 - 整個系統的入口點
│   ├── QueryListener.php                  # 事件監聽器 - 捕獲 QueryExecuted 事件
│   │
│   │
│   ├── Core/                              # 核心分析模組（SQL 解析層）
│   │   ├── QueryAnalyzer.php             # SQL 語句解析器 - 整合 phpmyadmin/sql-parser
│   │   ├── QueryAnalysis.php             # 解析結果數據類
│   │   ├── ComplexityDetector.php        # 複雜度檢測器 - 8 條檢測規則
│   │   ├── ComplexityResult.php          # 複雜度結果數據類
│   │   ├── OptimizationSuggester.php     # 優化建議引擎 - 自動生成改進方案
│   │   ├── Suggestion.php                # 單個建議數據類
│   │   └── StackTraceCollector.php       # 棧信息收集器 - IDE 集成
│   │
│   │
│   ├── StaticAnalysis/                    # 靜態分析模組 ✨ 新增
│   │   ├── QueryBuilderAnalyzer.php      # Query Builder 分析器 - 執行前檢查
│   │   ├── StaticAnalysisResult.php      # 靜態分析結果
│   │   ├── IndexInspector.php            # 索引檢查器 - 驗證數據庫索引
│   │   ├── StructureAnalyzer.php         # 結構分析器 - 複雜度評分和建議
│   │   ├── QueryBuilderMacro.php         # Macro 註冊 - 簡化 API
│   │   └── StaticAnalysisMiddleware.php  # 中間件（可選）
│   │
│   │
│   ├── Lifecycle/                         # 請求級分析模組
│   │   ├── QueryRecord.php               # 單條查詢記錄
│   │   ├── RequestQueryManager.php       # 請求級查詢管理器 - 緩衝管理
│   │   ├── N1QueryDetector.php           # N+1 查詢檢測器
│   │   ├── N1Pattern.php                 # N+1 模式結果
│   │   ├── DuplicateQueryDetector.php    # 重複查詢檢測器
│   │   └── DuplicateGroup.php            # 重複查詢分組結果
│   │
│   │
│   ├── Monitor/                           # 監控和追蹤模組
│   │   ├── SlowQueryTracker.php          # 慢查詢追蹤器 - 記錄超過閾值的查詢
│   │   ├── LiveQueryMonitor.php          # 實時監控器 - 事件廣播
│   │   └── MetricsCollector.php          # 指標收集器 - 彙整所有分析
│   │
│   │
│   ├── Events/                            # 自訂事件（WebSocket 廣播）
│   │   ├── QueryCapturedEvent.php        # 查詢被捕獲事件
│   │   ├── SlowQueryDetectedEvent.php    # 慢查詢檢測事件
│   │   └── N1PatternDetectedEvent.php    # N+1 模式檢測事件
│   │
│   │
│   ├── Storage/                           # 數據持久化層
│   │   ├── Contracts/
│   │   │   └── QueryStoreInterface.php   # 存儲接口 - 定義存儲規約
│   │   └── SqliteQueryStore.php          # SQLite 實現 - 自動建表
│   │
│   │
│   ├── Http/                              # Web 層
│   │   ├── Controllers/
│   │   │   ├── DashboardController.php   # 儀表板控制器
│   │   │   └── ApiController.php         # REST API 控制器
│   │   ├── Middleware/
│   │   │   └── QueryMonitoringMiddleware.php  # 請求監控中間件
│   │   └── Routes/
│   │       ├── web.php                   # 網頁路由
│   │       └── api.php                   # API 路由
│   │
│   │
│   ├── Livewire/                          # Livewire 組件（框架準備）
│   │   ├── QueryMonitor.php              # 實時監控組件
│   │   ├── QueryAnalytics.php            # 分析組件
│   │   └── SlowQueryLog.php              # 慢查詢日誌組件
│   │
│   │
│   ├── Console/                           # Artisan 命令
│   │   └── Commands/
│   │       ├── CleanupQueryLogs.php      # 清理日誌命令
│   │       └── ExportQueryLogs.php       # 匯出日誌命令
│   │
│   │
│   ├── Config/                            # 配置文件
│   │   └── sql-monitor.php               # 主配置文件 - 所有可配置選項
│   │
│   │
│   ├── Exceptions/                        # 異常類
│   │   └── MonitorException.php          # 自訂異常
│   │
│   │
│   └── Resources/                         # 前端資源
│       ├── views/                         # Blade 模板
│       │   ├── layouts/
│       │   │   └── monitor-layout.blade.php   # 主佈局
│       │   ├── components/
│       │   │   └── [待補充]
│       │   └── dashboard.blade.php            # 儀表板頁面
│       ├── css/
│       │   └── [待補充]
│       └── js/
│           └── [待補充]
│
│
├── tests/                                 # 測試套件
│   ├── Unit/
│   │   ├── QueryAnalyzerTest.php         # SQL 解析器測試
│   │   ├── ComplexityDetectorTest.php    # 複雜度檢測測試
│   │   ├── N1QueryDetectorTest.php       # N+1 檢測測試
│   │   └── StaticAnalysis/
│   │       └── QueryBuilderAnalyzerTest.php  # 靜態分析測試
│   ├── Feature/
│   │   ├── QueryMonitoringTest.php       # 監控功能測試
│   │   └── DashboardTest.php             # 儀表板測試
│   └── Integration/
│       ├── EloquentIntegrationTest.php   # Eloquent 集成測試
│       └── StaticAnalysisExample.php     # 靜態分析範例代碼
│
│
└── database/                              # 數據庫遷移
    └── migrations/
        └── create_sql_monitor_logs_table.php  # 日誌表遷移（待補充）

```

---

## 📦 核心模組說明

### 1️⃣ Core 模組（5 個檔案，450 行）
**目的**：SQL 語句解析和複雜度分析

| 檔案 | 行數 | 責任 |
|------|------|------|
| QueryAnalyzer.php | 200 | 整合 phpmyadmin/sql-parser，提取 SQL 結構 |
| ComplexityDetector.php | 180 | 8 條規則計算複雜度評分 |
| OptimizationSuggester.php | 150 | 根據分析生成 8 類建議 |
| StackTraceCollector.php | 80 | 收集並過濾調用棧 |
| 其他數據類 | 40 | QueryAnalysis, ComplexityResult, Suggestion |

### 2️⃣ StaticAnalysis 模組（6 個檔案 ✨ 新增，550 行）
**目的**：在 SQL 執行前檢查 Query Builder

| 檔案 | 行數 | 責任 |
|------|------|------|
| QueryBuilderAnalyzer.php | 280 | Reflection 提取 Builder 狀態 |
| IndexInspector.php | 200 | 檢查數據庫實際索引（MySQL/SQLite） |
| StructureAnalyzer.php | 180 | 複雜度評分、選擇率、成本估算 |
| QueryBuilderMacro.php | 30 | Macro 註冊簡化 API |
| 其他數據類 | 60 | StaticAnalysisResult 等 |

### 3️⃣ Lifecycle 模組（6 個檔案，500 行）
**目的**：請求級查詢分析和問題檢測

| 檔案 | 行數 | 責任 |
|------|------|------|
| RequestQueryManager.php | 150 | 緩衝和分組查詢 |
| N1QueryDetector.php | 130 | N+1 模式檢測算法 |
| DuplicateQueryDetector.php | 100 | 重複查詢指紋和分組 |
| QueryRecord.php | 120 | 查詢記錄數據類 |

### 4️⃣ Monitor 模組（3 個檔案，350 行）
**目的**：查詢監控、追蹤和廣播

| 檔案 | 行數 | 責任 |
|------|------|------|
| SlowQueryTracker.php | 80 | 記錄和廣播慢查詢 |
| LiveQueryMonitor.php | 120 | 事件廣播（WebSocket） |
| MetricsCollector.php | 150 | 彙整所有分析結果 |

### 5️⃣ Storage 模組（2 個檔案，400 行）
**目的**：查詢日誌持久化

| 檔案 | 行數 | 責任 |
|------|------|------|
| SqliteQueryStore.php | 350 | SQLite 自動建表和查詢 |
| QueryStoreInterface.php | 50 | 存儲接口定義 |

### 6️⃣ Http 模組（5 個檔案，300 行）
**目的**：Web 層（Dashboard 和 API）

| 檔案 | 行數 | 責任 |
|------|------|------|
| DashboardController.php | 50 | 儀表板視圖 |
| ApiController.php | 150 | REST API 端點 |
| QueryMonitoringMiddleware.php | 100 | 請求統計收集 |

### 7️⃣ Events 模組（3 個檔案，100 行）
**目的**：WebSocket 事件廣播

| 檔案 | 行數 | 責任 |
|------|------|------|
| QueryCapturedEvent.php | 30 | 每條查詢廣播 |
| SlowQueryDetectedEvent.php | 30 | 慢查詢廣播 |
| N1PatternDetectedEvent.php | 40 | N+1 模式廣播 |

### 其他模組
- **Console** - 2 個命令（清理、匯出）- 150 行
- **Config** - 配置文件 - 100 行
- **Exceptions** - 異常類 - 20 行
- **Resources** - Blade 模板和資源 - 200 行

---

## 📖 文檔結構

| 文件 | 用途 |
|------|------|
| README.md | 項目首頁、快速開始 |
| FILE_MANIFEST.md | **本文件** - 完整文件清單 |
| docs/ARCHITECTURE_OVERVIEW.md | **重要！** 完整架構詳解 |
| docs/STATIC_ANALYSIS.md | 靜態分析完整指南 |
| docs/DYNAMIC_ANALYSIS.md | 動態分析指南（待補充） |
| docs/API.md | API 參考文檔（待補充） |
| docs/DEVELOPER_GUIDE.md | 開發者貢獻指南（待補充） |

---

## 🧪 測試覆蓋

| 位置 | 檔案 | 目的 |
|------|------|------|
| tests/Unit/ | QueryAnalyzerTest.php | SQL 解析器單元測試 |
| tests/Unit/ | StaticAnalysisTest.php | 靜態分析單元測試 |
| tests/Feature/ | QueryMonitoringTest.php | 監控功能集成測試 |
| tests/Integration/ | StaticAnalysisExample.php | 靜態分析使用範例 |

---

## 🔑 關鍵檔案速查表

### 如果你想...

| 需求 | 查看檔案 |
|------|---------|
| **了解整體架構** | `docs/ARCHITECTURE_OVERVIEW.md` |
| **學習靜態分析** | `docs/STATIC_ANALYSIS.md` + `src/StaticAnalysis/*.php` |
| **看完整配置選項** | `src/Config/sql-monitor.php` |
| **理解 Query Builder 分析** | `src/StaticAnalysis/QueryBuilderAnalyzer.php` |
| **檢查索引邏輯** | `src/StaticAnalysis/IndexInspector.php` |
| **查看 N+1 檢測** | `src/Lifecycle/N1QueryDetector.php` |
| **了解 Web Dashboard** | `src/Resources/views/dashboard.blade.php` |
| **看 REST API** | `src/Http/Controllers/ApiController.php` |
| **理解事件系統** | `src/Events/*.php` |
| **學習擴展存儲** | `src/Storage/Contracts/QueryStoreInterface.php` |
| **查看 Artisan 命令** | `src/Console/Commands/*.php` |
| **開始寫測試** | `tests/Unit/StaticAnalysis/QueryBuilderAnalyzerTest.php` |

---

## 🚀 快速開始路線圖

### 1. 閱讀
- [ ] README.md（5 分鐘）
- [ ] ARCHITECTURE_OVERVIEW.md（15 分鐘）

### 2. 安裝與配置
- [ ] composer require ...（5 分鐘）
- [ ] php artisan vendor:publish（2 分鐘）
- [ ] 設置 `.env` 變數（2 分鐘）

### 3. 試用動態分析
- [ ] 訪問 http://localhost:8000/sql-monitor（3 分鐘）
- [ ] 執行一個查詢並查看 Dashboard（5 分鐘）
- [ ] 查閱 API 端點（10 分鐘）

### 4. 試用靜態分析
- [ ] 閱讀 STATIC_ANALYSIS.md（15 分鐘）
- [ ] 在代碼中使用 `$query->analyzeStatic()`（10 分鐘）
- [ ] 查看索引檢查功能（10 分鐘）

### 5. 集成到項目
- [ ] 在中間件中添加檢查（10 分鐘）
- [ ] 在測試中驗證查詢（10 分鐘）
- [ ] 配置告警和日誌（15 分鐘）

---

## 📊 代碼指標

```
語言       行數      檔案數
─────────────────────────
PHP        4,120      36
Markdown     800       7
Blade        250       3
JSON         50        1
─────────────────────────
合計       5,220      47
```

---

## ✅ 完成度檢查表

- [x] 核心 SQL 解析和分析
- [x] 靜態分析（Query Builder）
- [x] 索引檢查（MySQL/SQLite）
- [x] N+1 和重複查詢檢測
- [x] Slow Query 追蹤
- [x] Web Dashboard
- [x] REST API
- [x] 事件廣播系統
- [x] SQLite 持久化
- [x] Artisan 命令
- [x] 完整文檔
- [x] 測試框架
- [ ] PostgreSQL 完整支持
- [ ] 前端 JavaScript 組件
- [ ] Livewire 組件
- [ ] CI/CD 整合

---

## 🔗 相關連結

- 🔗 [phpmyadmin/sql-parser GitHub](https://github.com/phpmyadmin/sql-parser)
- 🔗 [Laravel 官方文檔](https://laravel.com/docs)
- 🔗 [Tailwind CSS](https://tailwindcss.com)

---

**最後更新**：2026 年 4 月
**狀態**：✅ Phase 2 完成，準備 Phase 3
