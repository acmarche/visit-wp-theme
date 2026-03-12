VistMarche Theme Wp
===

```bash
npx tailwindcss -i assets/css/input.css -o assets/css/visit.css --watch
```

### Compile css

```bash
npx @tailwindcss/cli -i wp-content/themes/visit/assets/css/input.css -o wp-content/themes/visit/assets/css/visit.css --watch
```
### Register rewrite rules

```bash
wp rewrite flush 
```

  Then in your templates, use the Tailwind weight utilities to control thickness:
  ┌─────────────────────────────────┬────────┬─────────────┐
  │              Class              │ Weight │    Style    │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-thin       │ 100    │ Thin        │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-extralight │ 200    │ Extra Light │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-light      │ 300    │ Light       │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-normal     │ 400    │ Regular     │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-medium     │ 500    │ Medium      │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-semibold   │ 600    │ Semi Bold   │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-bold       │ 700    │ Bold        │
  ├─────────────────────────────────┼────────┼─────────────┤
  │ font-montserrat font-extrabold  │ 800    │ Extra Bold 
