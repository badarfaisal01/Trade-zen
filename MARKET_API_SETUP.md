# 🚀 Real Market API Integration Setup Guide

## ✅ What We've Added

Your trading platform now has **Real Market Data Integration** with support for:
- 📊 **Twelve Data API** - Forex, Crypto, and Stock prices
- 💎 **Metals API** - Gold, Silver, and Precious Metals

---

## 📋 Step 1: Get Your API Keys

### **Twelve Data (Forex & Crypto)**

1. Visit: https://twelvedata.com
2. Click **"Sign up for free"**
3. Create your account (takes 2 minutes)
4. Go to **API Keys** section in your dashboard
5. Copy your **Free Tier API Key**
6. Your free tier includes: **800 API calls/day**

### **Metals API (Gold & Precious Metals)**

1. Visit: https://metals-api.com/start
2. Sign up for free account
3. Go to your dashboard
4. Get your **access_key**
5. Your free tier includes: **100 API calls/month**

---

## 🔧 Step 2: Add API Keys to .env File

Edit your `.env` file and add:

```env
# Market Data APIs
TWELVEDATA_API_KEY=your_actual_key_here
METALS_API_KEY=your_actual_key_here
```

**Location:** `c:\xampp\htdocs\.env`

**Example:**
```env
TWELVEDATA_API_KEY=abc123def456ghi789
METALS_API_KEY=xyz789uvw456rst123
```

---

## ✨ Step 3: Access the Market API Admin Page

1. **Log in to Admin Dashboard**
   - URL: `http://localhost/admin/`
   - Email: `admin@55ak.xyz`
   - Password: `12345678`

2. **Navigate to Market APIs**
   - Look for **"Market APIs"** in the left sidebar menu
   - Or go directly to: `http://localhost/admin/?page=market_api`

3. **Test Your Connection**
   - Click the **"Test API Connections"** button
   - You should see green ✓ status for both APIs

---

## 📈 Step 4: Configure Markets to Use Real Data

1. Go to **Trading Control** → **Assets** page
2. For each market (EURUSD, XAUUSD, etc.):
   - Click **Edit Market**
   - Set **Price Source** to **"API"**
   - Enter the **API Symbol** (e.g., EURUSD, XAUUSD)
   - Click **Save**

3. The chart will now show real market prices!

---

## 🎯 Supported Symbols

### **Twelve Data (Forex & Crypto)**
- ✅ EURUSD (Euro/Dollar)
- ✅ GBPUSD (British Pound)
- ✅ USDJPY (US Dollar/Japanese Yen)
- ✅ AUDCAD (Australian/Canadian Dollar)
- ✅ EURJPY (Euro/Yen)
- ✅ BTCUSD (Bitcoin)
- ✅ ETHUSD (Ethereum)

### **Metals API (Commodities)**
- ✅ XAUUSD (Gold)
- ✅ XAGUSD (Silver)
- ✅ XPDUSD (Palladium)
- ✅ XPTUSD (Platinum)

---

## 🧪 API Test Endpoints

### **Test Twelve Data Price**
```
GET /en/api/market-api.php?symbol=EURUSD&action=price
```

**Response:**
```json
{
  "ok": true,
  "symbol": "EURUSD",
  "price": 1.08642,
  "timestamp": 1693478532,
  "source": "twelvedata"
}
```

### **Test API Connection**
```
GET /en/api/market-api.php?action=test
```

---

## 🐛 Troubleshooting

### ❌ "API key not configured"
**Fix:** Make sure you added the API key to `.env` and the values are correct

### ❌ "No price data for symbol"
**Fix:** Check that the symbol is supported by the API (see supported symbols above)

### ❌ "HTTP 401 or 403"
**Fix:** Your API key is invalid or expired. Get a new one from the API provider

### ❌ "cURL not enabled"
**Fix:** Make sure PHP cURL extension is enabled (it should be in XAMPP by default)

---

## 📊 Real-Time Price Updates

Once configured:
1. Users see **live market prices** in the trading chart
2. Prices update every **650ms** (configurable in Settings)
3. Trade settlement uses **real API prices**
4. Historical candles are generated from real price data

---

## 💡 API Limitations & Quotas

| API | Free Tier | Rate Limit | Update Frequency |
|-----|-----------|------------|------------------|
| Twelve Data | 800 calls/day | 1 call/sec | Real-time |
| Metals API | 100 calls/month | Unlimited | Daily |

**Recommendation:** Cache API responses for 60 seconds to stay within limits

---

## 🔐 Security Notes

1. **Never** commit your `.env` file to version control
2. **API keys are stored server-side** - never sent to browsers
3. Rotate API keys periodically
4. Monitor your API usage in the provider dashboards

---

## 📞 Support

If you encounter issues:

1. Check the **Admin Dashboard** → **Market APIs** page for connection status
2. Review error messages in the test results
3. Verify API keys in your `.env` file
4. Check PHP logs: `c:\xampp\htdocs\storage\logs\app.log`

---

## 🎉 You're All Set!

Your trading platform now has **real, live market data**. Users can:
- ✅ See real forex prices
- ✅ Trade on actual gold prices
- ✅ View authentic market charts
- ✅ Get accurate trade settlements

Enjoy your enhanced trading platform! 🚀
