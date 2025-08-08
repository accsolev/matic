<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DA/PA Domain Checker</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.32/sweetalert2.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.32/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .form-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .form-title {
            font-size: 2em;
            font-weight: 700;
            color: #374151;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            resize: vertical;
            transition: border-color 0.3s ease;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .submit-btn {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Loading */
        .loading {
            background: white;
            border-radius: 20px;
            padding: 60px 20px;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f4f6;
            border-top: 4px solid #6366f1;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 1.2em;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .loading-subtitle {
            color: #6b7280;
        }

        /* Domain Card */
        .domain-card {
            background: white;
            border-radius: 20px;
            margin: 30px 0;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .domain-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            color: white;
            position: relative;
        }

        .domain-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 80%, rgba(255,255,255,0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            z-index: 1;
        }

        .domain-title {
            font-size: 2.2em;
            font-weight: 700;
            margin: 0 0 8px 0;
        }

        .domain-subtitle {
            opacity: 0.9;
            margin: 0;
        }

        .visit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .visit-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        /* Content */
        .domain-content {
            padding: 40px 30px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.4em;
            font-weight: 700;
            color: #374151;
            margin: 0 0 25px 0;
        }

        .section-icon {
            width: 24px;
            height: 24px;
            color: #6366f1;
        }

        /* Metrics */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 25px;
            border: 1px solid #e2e8f0;
        }

        .metric-card h5 {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.1em;
            font-weight: 600;
            color: #475569;
            margin: 0 0 20px 0;
        }

        .metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .metric-row:last-child {
            border-bottom: none;
        }

        .metric-label {
            color: #64748b;
            font-size: 0.9em;
        }

        .metric-value {
            font-weight: 700;
            color: #1e293b;
        }

        .metric-value.success {
            color: #059669;
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-radius: 16px;
            padding: 25px;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            transform: rotate(45deg);
        }

        .stat-label {
            font-size: 0.8em;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .stat-value {
            font-size: 2em;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        /* Chart */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin: 30px 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #374151;
            margin: 0;
        }

        .chart-period {
            font-size: 0.85em;
            color: #6b7280;
            background: #f3f4f6;
            padding: 6px 12px;
            border-radius: 20px;
        }

        .chart-canvas {
            position: relative;
            height: 250px !important;
        }

        /* Countries */
        .countries {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 20px;
        }

        .country-tag {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.8em;
            font-weight: 500;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .country-flag {
            font-weight: 700;
            margin-right: 4px;
        }

        /* Lists */
        .lists-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }

        .list-container {
            background: #f8fafc;
            border-radius: 16px;
            padding: 25px;
            border: 1px solid #e2e8f0;
        }

        .list-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.1em;
            font-weight: 600;
            color: #374151;
            margin: 0 0 20px 0;
        }

        .data-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            transition: background-color 0.2s ease;
        }

        .list-item:hover {
            background: rgba(99, 102, 241, 0.05);
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
            flex: 1;
            margin-right: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .list-item a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .list-value {
            font-weight: 700;
            color: #374151;
            font-size: 0.9em;
        }

        .keyword-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            margin-right: 10px;
        }

        .keyword-name {
            font-weight: 500;
            color: #374151;
        }

        .keyword-rank {
            background: #6366f1;
            color: white;
            font-size: 0.75em;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        /* Error */
        .error {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .error-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .error-icon {
            width: 20px;
            height: 20px;
            color: #ef4444;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .error-title {
            font-weight: 600;
            color: #dc2626;
            margin: 0 0 4px 0;
        }

        .error-message {
            color: #b91c1c;
            margin: 0;
            font-size: 0.9em;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .domain-title {
                font-size: 1.8em;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .domain-content {
                padding: 30px 20px;
            }
            
            .metrics-grid,
            .lists-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Form -->
        <div class="form-container">
            <h1 class="form-title">🔍 DA/PA Domain Checker</h1>
            <form id="formCheckDA">
                <div class="form-group">
                    <label class="form-label" for="domain">Masukkan Domain (satu per baris):</label>
                    <textarea 
                        name="domain" 
                        id="domain" 
                        class="form-textarea" 
                        placeholder="Contoh:&#10;marontoto.org&#10;google.com&#10;github.com"
                        required
                    ></textarea>
                </div>
                <button type="submit" class="submit-btn" id="submitBtn">
                    Analisis Domain
                </button>
            </form>
        </div>

        <!-- Results -->
        <div id="resultDA"></div>
    </div>

    <script>
        const isVIP = true; // Set to true for demo, ganti dengan <?= json_encode($isVIP); ?>
        let charts = [];

        function formatNumber(value) {
            if (!value || isNaN(value)) return '-';
            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
            if (value >= 1000) return (value / 1000).toFixed(1) + 'K';
            return value.toLocaleString();
        }

        function formatCurrency(value) {
            return value ? '$' + Math.round(value).toLocaleString() : '-';
        }

        function getCountryFlag(countryCode) {
            const flags = {
                'id': '🇮🇩',
                'us': '🇺🇸',
                'kh': '🇰🇭',
                'sg': '🇸🇬',
                'uk': '🇬🇧',
                'de': '🇩🇪',
                'fr': '🇫🇷',
                'jp': '🇯🇵',
                'kr': '🇰🇷',
                'cn': '🇨🇳'
            };
            return flags[countryCode?.toLowerCase()] || '🌍';
        }

        function createLoadingHTML() {
            return `
                <div class="loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Menganalisis Domain...</div>
                    <div class="loading-subtitle">Sedang mengambil data dari API, mohon tunggu sebentar</div>
                </div>
            `;
        }

        function createErrorHTML(domain, error) {
            return `
                <div class="error">
                    <div class="error-content">
                        <svg class="error-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                        </svg>
                        <div>
                            <h4 class="error-title">Gagal memproses domain</h4>
                            <p class="error-message"><strong>${domain}:</strong> ${error}</p>
                        </div>
                    </div>
                </div>
            `;
        }

        function renderDomainCard(data, index) {
            if (!data.success || !data.ahrefs || !data.traffic) {
                return createErrorHTML(data.domain || 'Unknown', 'Data tidak tersedia');
            }

            const { ahrefs, traffic, domain: rawDomain } = data;
            const { domain, page } = ahrefs;
            const cleanDomain = rawDomain.replace(/^https?:\/\//, '');
            
            const trafficHistory = traffic.traffic_history || [];
            const countries = traffic.top_countries || [];
            const pages = traffic.top_pages || [];
            const keywords = traffic.top_keywords || [];

            return `
                <div class="domain-card">
                    <div class="domain-header">
                        <div class="header-content">
                            <div>
                                <h3 class="domain-title">${cleanDomain}</h3>
                                <p class="domain-subtitle">Analisis Lengkap Domain & Traffic</p>
                            </div>
                            <a href="${rawDomain}" target="_blank" class="visit-btn">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4"/>
                                    <path d="M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                                Kunjungi
                            </a>
                        </div>
                    </div>

                    <div class="domain-content">
                        <!-- Metrics Section -->
                        <h4 class="section-title">
                            <svg class="section-icon" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Metrik Ahrefs
                        </h4>
                        
                        <div class="metrics-grid">
                            <!-- Domain Metrics -->
                            <div class="metric-card">
                                <h5>
                                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                                    </svg>
                                    Metrik Domain
                                </h5>
                                <div class="metric-row">
                                    <span class="metric-label">Domain Rating:</span>
                                    <span class="metric-value">${domain.domainRating || '-'}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Ahrefs Rank:</span>
                                    <span class="metric-value">${formatNumber(domain.ahrefsRank)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Backlinks:</span>
                                    <span class="metric-value">${formatNumber(domain.backlinks)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Referring Domains:</span>
                                    <span class="metric-value">${formatNumber(domain.refDomains)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Organic Traffic:</span>
                                    <span class="metric-value success">${formatNumber(domain.traffic)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Traffic Value:</span>
                                    <span class="metric-value success">${formatCurrency(domain.trafficValue)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Organic Keywords:</span>
                                    <span class="metric-value">${formatNumber(domain.organicKeywords)}</span>
                                </div>
                            </div>

                            <!-- Page Metrics -->
                            <div class="metric-card">
                                <h5>
                                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z"/>
                                    </svg>
                                    Metrik Halaman
                                </h5>
                                <div class="metric-row">
                                    <span class="metric-label">URL Rating:</span>
                                    <span class="metric-value">${page.urlRating || '-'}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Page Backlinks:</span>
                                    <span class="metric-value">${formatNumber(page.backlinks)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Page Ref Domains:</span>
                                    <span class="metric-value">${formatNumber(page.refDomains)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Page Traffic:</span>
                                    <span class="metric-value success">${formatNumber(page.traffic)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Traffic Value:</span>
                                    <span class="metric-value success">${formatCurrency(page.trafficValue)}</span>
                                </div>
                                <div class="metric-row">
                                    <span class="metric-label">Words on Page:</span>
                                    <span class="metric-value">${formatNumber(page.numberOfWordsOnPage)}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Traffic Stats -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-label">Traffic Bulanan</div>
                                <div class="stat-value">${formatNumber(traffic.trafficMonthlyAvg)}</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Nilai Traffic</div>
                                <div class="stat-value">${formatCurrency(traffic.costMonthlyAvg)}</div>
                            </div>
                        </div>

                        <!-- Traffic Chart -->
                        <div class="chart-container">
                            <div class="chart-header">
                                <h4 class="chart-title">📈 Riwayat Traffic Organik</h4>
                                <span class="chart-period">${trafficHistory.length} bulan terakhir</span>
                            </div>
                            <canvas id="chartTraffic${index}" class="chart-canvas"></canvas>
                            
                            ${countries.length > 0 ? `
                                <div class="countries">
                                    ${countries.map(c => `
                                        <span class="country-tag">
                                            <span class="country-flag">${getCountryFlag(c.country)}</span>
                                            <span style="margin-left: 4px;">${(c.share || 0).toFixed(1)}%</span>
                                        </span>
                                    `).join('')}
                                </div>
                            ` : ''}
                        </div>

                        <!-- Lists -->
                        <div class="lists-grid">
                            <!-- Top Pages -->
                            <div class="list-container">
                                <h5 class="list-title">
                                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z"/>
                                    </svg>
                                    📄 Halaman Teratas
                                </h5>
                                <ul class="data-list">
                                    ${pages.length > 0 ? pages.map(p => `
                                        <li class="list-item">
                                            <a href="${p.url}" target="_blank">${p.url}</a>
                                            <span class="list-value">${formatNumber(p.traffic)}</span>
                                        </li>
                                    `).join('') : '<li class="list-item">Tidak ada data halaman</li>'}
                                </ul>
                            </div>

                            <!-- Top Keywords -->
                            <div class="list-container">
                                <h5 class="list-title">
                                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"/>
                                    </svg>
                                    🔍 Kata Kunci Teratas
                                </h5>
                                <ul class="data-list">
                                    ${keywords.length > 0 ? keywords.map(k => `
                                        <li class="list-item">
                                            <div class="keyword-info">
                                                <span class="keyword-name">${k.keyword}</span>
                                                <span class="keyword-rank">Rank: ${k.position}</span>
                                            </div>
                                            <span class="list-value">${formatNumber(k.traffic)}</span>
                                        </li>
                                    `).join('') : '<li class="list-item">Tidak ada data kata kunci</li>'}
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function createChart(canvasId, data) {
            const ctx = document.getElementById(canvasId);
            if (!ctx || !data || data.length === 0) return;

            try {
                const chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.map(x => {
                            const date = new Date(x.date);
                            return date.toLocaleDateString('id-ID', { month: 'short', year: 'numeric' });
                        }),
                        datasets: [{
                            label: 'Traffic Organik',
                            data: data.map(x => x.organic),
                            fill: true,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            tension: 0.4,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            pointBackgroundColor: '#6366f1',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            borderWidth: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                borderColor: '#6366f1',
                                borderWidth: 1,
                                callbacks: {
                                    label: function(context) {
                                        return 'Traffic: ' + formatNumber(context.parsed.y);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true,
                                grid: { color: '#f3f4f6' },
                                ticks: { 
                                    color: '#6b7280',
                                    callback: function(value) {
                                        return formatNumber(value);
                                    }
                                }
                            },
                            x: { 
                                grid: { color: '#f3f4f6' },
                                ticks: { color: '#6b7280' }
                            }
                        }
                    }
                });
                charts.push(chart);
            } catch (error) {
                console.error('Chart creation failed:', error);
            }
        }

        // Main form handler
        document.getElementById('formCheckDA').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!isVIP) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Akses Ditolak',
                    text: 'Maaf, hanya user VIP/VIPMAX yang dapat melakukan pengecekan DA/PA & Umur Domain.',
                    confirmButtonColor: '#6366f1'
                });
                return;
            }

            const domains = this.domain.value.split('\n').map(d => d.trim()).filter(Boolean);
            const resultBox = document.getElementById('resultDA');
            const submitBtn = document.getElementById('submitBtn');
            
            if (domains.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Input Kosong',
                    text: 'Silakan masukkan minimal satu domain untuk dicek.',
                    confirmButtonColor: '#6366f1'
                });
                return;
            }

            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sedang Menganalisis...';

            // Destroy existing charts
            charts.forEach(chart => {
                try { chart.destroy(); } catch(e) {}
            });
            charts = [];

            // Show loading
            resultBox.innerHTML = createLoadingHTML();

            let hasResults = false;

            for (let i = 0; i < domains.length; i++) {
                const domain = domains[i];
                
                try {
                    // API call dengan POST method seperti yang asli
                    const formData = new FormData();
                    formData.append('domain', domain);

                    const response = await fetch('/dashboard/ajax/check-da-pa.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    
                    if (!hasResults) {
                        resultBox.innerHTML = '';
                        hasResults = true;
                    }
                    
                    resultBox.innerHTML += renderDomainCard(data, i);
                    
                    // Create chart after a delay
                    setTimeout(() => {
                        const trafficHistory = data.traffic?.traffic_history || [];
                        if (trafficHistory.length > 0) {
                            createChart(`chartTraffic${i}`, trafficHistory);
                        }
                    }, 300);

                } catch (error) {
                    console.error(`Error checking domain ${domain}:`, error);
                    
                    if (!hasResults) {
                        resultBox.innerHTML = '';
                        hasResults = true;
                    }
                    
                    resultBox.innerHTML += createErrorHTML(domain, error.message);
                }
            }

            if (!hasResults) {
                resultBox.innerHTML = `
                    <div class="error">
                        <div class="error-content">
                            <svg class="error-icon" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                            </svg>
                            <div>
                                <h4 class="error-title">Tidak Ada Data</h4>
                                <p class="error-message">Tidak berhasil mendapatkan data untuk semua domain yang dicek.</p>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Re-enable submit button
            submitBtn.disabled = false;
            submitBtn.textContent = 'Analisis Domain';
        });

        // Demo with sample data
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('domain').value = 'marontoto.org';
        });
    </script>
</body>
</html>