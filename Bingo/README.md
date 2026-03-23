# Bingo App (React)

This folder contains the React application for the Bingo project.

Canonical project documentation lives at:
- `/Users/dewittsteward/Documents/Bingo/README.md`

## Commands

```bash
npm install
HOST=127.0.0.1 PORT=3000 npm start
npm run build
```

## Notes

- Build copies `../data/Books.json` and `../data/Bingo Cards.json` into `public/` before bundling.
- Local dev API routes are implemented in `src/setupProxy.js`.
