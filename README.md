# Sistema de Ponto Eletrônico para Professores (PHP + Bootstrap + MySQL PDO + Reconhecimento Facial)

Sistema completo para registro de ponto (entrada/saída) de professores com recursos avançados. Inclui:
- **Reconhecimento facial** via face-api.js (TensorFlow.js) no navegador com suporte a múltiplos descritores
- **Validação geográfica** baseada em localização de escolas
- **Múltiplas fotos** por check-in para maior precisão
- **CRUD de escolas** com coordenadas geográficas e raios de validação
- **Relatórios filtrados** por registros aprovados
- Captura e cadastro de face para cada professor
- Alternativa por PIN
- Painel administrativo completo para gerenciar professores, escolas e visualizar batidas
- MySQL (PDO), PHP, Bootstrap

## ⚠️ ATENÇÃO
- Para a câmera funcionar no navegador, acesse via HTTPS ou em http://localhost (origem segura).
- Reconhecimento facial é feito no cliente (navegador). A verificação e gravação do ponto são validadas no servidor (PHP) comparando múltiplos descritores faciais contra os salvos no banco.

## 🚀 Novidades da Versão Atual

### 1. CRUD de Escolas e Geolocalização
- **Gerenciamento de escolas** com coordenadas (lat/lng) e raio de validação
- **Validação automática de localização** durante check-in
- **Vínculo professor-escola** para controle de acesso por localização
- Professores podem estar vinculados a múltiplas escolas ou à rede completa

### 2. Relatórios com Filtro de Aprovação
- **Filtro automático** `approved = 1` em todos os relatórios e exports
- **Registros pendentes** (NULL) e reprovados (0) não impactam folha de pagamento
- **Exports Excel/PDF** consideram apenas registros aprovados
- Totalizadores e banco de horas baseados apenas em registros aprovados

### 3. Múltiplas Fotos e Descritores Faciais
- **Suporte a até 5 fotos adicionais** por check-in para maior robustez
- **Múltiplos descritores faciais** comparados simultaneamente
- **Melhor score facial** entre todos os descritores enviados
- **Armazenamento seguro** com SHA-256 das fotos adicionais
- **Validação aprimorada** com notas detalhadas para auditoria

## 📋 Requisitos
- PHP 8.0+
- MySQL 5.7+ (ou MariaDB com suporte a JSON)
- Servidor web apontando para a pasta `public/` como DocumentRoot (recomendado)

## 🛠️ Instalação

### 1. Para instalações novas:
```bash
# Importe o banco atualizado
mysql -u root -p your_database_name < install.sql
```

### 2. Para atualizações de versões anteriores:
```bash
# Execute a migração
mysql -u root -p your_database_name < migrations/001_add_geographic_fields_and_multiple_photos.sql
```

### 3. Configure o banco em `config.php`:
```php
$servidor = 'localhost';
$usuario = 'root';
$senha = '';
$banco = 'ponto';
```

### 4. Acesse o painel admin:
- Vá para `/public/admin/login.php`
- Na primeira execução, usuário admin padrão será criado:
  - **Usuário:** `admin`
  - **Senha:** `admin123`
- **⚠️ Altere a senha imediatamente!**

### 5. Configure escolas:
- Acesse **Admin > Instituições**
- Cadastre escolas com coordenadas geográficas
- Defina o raio de validação (padrão: 300m)

### 6. Cadastre professores:
- Acesse **Admin > Colaboradores**
- Crie professores e vincule às escolas
- Capture múltiplas faces para melhor precisão

## 📱 Uso do Sistema

### Check-in/Check-out:
- Professores acessam `/public/index.php`
- **Reconhecimento facial:** Envie múltiplas fotos para maior precisão
- **Alternativa PIN:** Use email + PIN de 6 dígitos
- **Validação automática:** Localização + face + qualidade da foto

### Formato da API de Check-in:
```json
{
  "cpf": "12345678901",
  "pin": "123456",
  "photo": "data:image/jpeg;base64,...",
  "photos": [
    "data:image/jpeg;base64,...",
    "data:image/jpeg;base64,..."
  ],
  "face_descriptor": [0.1, 0.2, ...],
  "face_descriptors": [
    [0.1, 0.2, ...],
    [0.3, 0.4, ...]
  ],
  "geo": {
    "lat": -15.7942287,
    "lng": -47.8821658,
    "acc": 5.0
  }
}
```

### Critérios de Auto-aprovação:
✅ **Aprovado automaticamente quando:**
- Foto principal com qualidade adequada
- Localização dentro do raio de uma escola vinculada
- Reconhecimento facial bem-sucedido (score ≥ 0.6)

❌ **Fica pendente quando:**
- Qualquer critério acima falha
- Admin deve aprovar/reprovar manualmente

## 📊 Relatórios

### Comportamento de approved = 1:
- **Relatórios mensais:** Apenas registros aprovados
- **Exports Excel/PDF:** Filtram automaticamente por approved = 1
- **Cálculos financeiros:** Baseados apenas em registros aprovados
- **Banco de horas:** Considera apenas approved = 1

### Tipos de registros:
- `approved = 1`: Aprovado (conta para relatórios)
- `approved = 0`: Reprovado (não conta)
- `approved = NULL`: Pendente (não conta até aprovação)

## 🔧 Configurações

### Ajuste de precisão facial:
```php
// Em lib/FaceRecognition.php
$threshold = 0.6; // Padrão: 0.6 (ajuste conforme necessário)
```

### Limites de upload:
- **Fotos adicionais:** Máximo 5 por check-in
- **Tamanho:** Controlado por quality analysis
- **Formatos:** JPEG via base64 data URLs

## 📁 Estrutura Atualizada
```
├── config.php              # Conexão PDO e configurações
├── helpers.php              # Autenticação, CSRF, utilidades
├── install.sql              # Schema completo do banco (atualizado)
├── migrations/              # Migrações incrementais
│   ├── 001_add_geographic_fields_and_multiple_photos.sql
│   └── README.md
├── lib/
│   └── FaceRecognition.php  # Biblioteca de reconhecimento facial
├── public/
│   ├── index.php            # Página de check-in
│   ├── photos/              # Fotos dos check-ins
│   └── admin/               # Painel administrativo
│       ├── schools.php      # CRUD de escolas
│       ├── school_edit.php  # Formulário de escola
│       ├── teachers.php     # Gestão de professores
│       ├── teacher_edit.php # Formulário de professor (c/ escolas)
│       ├── reports.php      # Relatórios (approved = 1)
│       └── ...
└── api/
    ├── checkin.php          # Check-in com múltiplas fotos
    └── save_face.php        # Cadastro de descritores faciais
```

## 🔒 Segurança

### Produção:
- **HTTPS obrigatório** (câmera + geolocalização)
- **CSRF tokens** implementados
- **Validação de escopo** admin/escola
- **Hashes SHA-256** das fotos
- **Logs de auditoria** completos

### Privacidade:
- **Descritores faciais** (não imagens) armazenados
- **Geolocalização** validada apenas no servidor
- **Fotos temporárias** com cleanup automático

## 🧪 Testes Manuais Sugeridos

1. **Escolas:**
   - Criar 2 escolas com raios diferentes
   - Vincular professor a ambas
   - Testar check-in próximo/distante

2. **Face recognition:**
   - Enviar 3 descritores: 1 ruim, 2 bons
   - Verificar se best_score determina sucesso

3. **Relatórios:**
   - Criar registros pending/approved/rejected
   - Verificar filtro approved = 1 nos exports

4. **Múltiplas fotos:**
   - Upload de 5 fotos no check-in
   - Verificar armazenamento em attendance_photos

## 📞 Suporte

Para questões técnicas, consulte:
- **Migrations:** `migrations/README.md`
- **Face Recognition:** `lib/FaceRecognition.php`
- **Database Schema:** `install.sql`

---
**Versão:** 2.0 com Geolocalização, Múltiplas Fotos e Relatórios Aprovados