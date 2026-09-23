# 📋 Motivos para Inserção Manual de Ponto

## 🔧 Problemas Técnicos

### **Sistema/Dispositivo**
- ✅ **Falha no sistema de ponto eletrônico**
- ✅ **Dispositivo sem bateria**
- ✅ **Celular sem acesso no momento**
- ✅ **Aplicativo apresentou erro técnico**
- ✅ **Sem conexão com internet**
- ✅ **Falha no reconhecimento facial**
- ✅ **Erro ao capturar foto**
- ✅ **GPS não disponível no momento**
- ✅ **Sistema em manutenção**
- ✅ **Esquecimento de registrar ponto no horário**

---

## 🏥 Situações Especiais

### **Saúde**
- ✅ **Atendimento médico durante expediente**
- ✅ **Emergência médica**
- ✅ **Acompanhamento de familiar em consulta**
- ✅ **Exame médico periódico**

### **Ausências Justificadas**
- ✅ **Licença previamente autorizada**
- ✅ **Atestado médico apresentado**
- ✅ **Comparecimento em juízo**
- ✅ **Doação de sangue**
- ✅ **Alistamento militar**
- ✅ **Casamento (licença-gala)**
- ✅ **Falecimento de familiar (luto)**
- ✅ **Licença-paternidade/maternidade**

---

## 🚗 Deslocamento e Logística

### **Trabalho Externo**
- ✅ **Trabalho em campo sem acesso ao sistema**
- ✅ **Visita técnica externa**
- ✅ **Reunião fora da instituição**
- ✅ **Deslocamento entre unidades**
- ✅ **Evento externo representando a instituição**
- ✅ **Treinamento externo**
- ✅ **Trabalho em outra unidade (rede)**

### **Transporte**
- ✅ **Problema no transporte público**
- ✅ **Acidente no trajeto**
- ✅ **Condições climáticas adversas**
- ✅ **Greve de transporte público**

---

## 🏢 Situações Administrativas

### **Autorizações Prévias**
- ✅ **Compensação de horas autorizada**
- ✅ **Banco de horas (saldo positivo)**
- ✅ **Folga compensatória**
- ✅ **Home office autorizado**
- ✅ **Horário especial autorizado**
- ✅ **Flexibilização de jornada**

### **Processos Internos**
- ✅ **Integração/Treinamento de novos colaboradores**
- ✅ **Reunião interna emergencial**
- ✅ **Participação em processo seletivo interno**
- ✅ **Atividade pedagógica não prevista**
- ✅ **Reunião de planejamento**

---

## 📚 Situações Acadêmicas (Educação)

### **Professores**
- ✅ **Aula remota autorizada**
- ✅ **Conselho de classe**
- ✅ **Reunião pedagógica**
- ✅ **Capacitação docente**
- ✅ **Substituição de professor ausente**
- ✅ **Aula extra autorizada**
- ✅ **Excursão pedagógica**
- ✅ **Feira de ciências**
- ✅ **Olimpíadas/Jogos escolares**
- ✅ **Formatura/Colação de grau**

---

## 🔄 Correções

### **Ajustes Necessários**
- ✅ **Correção de horário registrado incorretamente**
- ✅ **Esquecimento de registrar saída**
- ✅ **Esquecimento de registrar entrada**
- ✅ **Registro duplicado (correção)**
- ✅ **Ajuste conforme atestado médico**
- ✅ **Regularização após análise de gestor**

---

## 🚨 Emergências

### **Situações de Urgência**
- ✅ **Emergência familiar**
- ✅ **Emergência pessoal**
- ✅ **Problema doméstico urgente**
- ✅ **Sinistro (incêndio, enchente, etc)**
- ✅ **Violência/Assalto**

---

## 🎯 Outros Motivos Comuns

- ✅ **Saída antecipada autorizada**
- ✅ **Chegada antecipada não registrada**
- ✅ **Hora extra pré-autorizada**
- ✅ **Feriado municipal/estadual**
- ✅ **Ponto facultativo**
- ✅ **Recesso escolar**
- ✅ **Férias coletivas**
- ✅ **Colaborador novo (primeiro dia)**
- ✅ **Migração de sistema anterior**
- ✅ **Importação de dados legados**

---

## 💡 Dicas de Uso

### **Motivos mais usados (Top 10):**
1. Falha no sistema de ponto eletrônico
2. Esquecimento de registrar ponto no horário
3. Sem conexão com internet
4. Atestado médico apresentado
5. Trabalho em campo sem acesso ao sistema
6. Correção de horário registrado incorretamente
7. Home office autorizado
8. Compensação de horas autorizada
9. Esquecimento de registrar saída
10. Dispositivo sem bateria

### **Como categorizar:**
- **Técnicos**: Problemas com sistema, app, dispositivo
- **Saúde**: Atestados, consultas, emergências médicas
- **Administrativos**: Autorizações, compensações, folgas
- **Correções**: Ajustes de registros anteriores
- **Emergências**: Situações imprevistas e urgentes

---

## 🔐 Boas Práticas

1. **Seja específico**: "Atestado médico apresentado" é melhor que "Doença"
2. **Indique autorização**: "Home office autorizado" deixa claro que foi aprovado
3. **Documente**: Sempre solicite comprovante quando aplicável
4. **Padronize**: Use os mesmos termos para facilitar relatórios
5. **Revise periodicamente**: Adicione novos motivos conforme necessidade

---

## 📊 SQL para Inserção Rápida

```sql
-- Inserir todos os motivos de uma vez
INSERT INTO manual_reasons (name, active) VALUES
('Falha no sistema de ponto eletrônico', 1),
('Dispositivo sem bateria', 1),
('Sem conexão com internet', 1),
('Esquecimento de registrar ponto no horário', 1),
('Atestado médico apresentado', 1),
('Trabalho em campo sem acesso ao sistema', 1),
('Correção de horário registrado incorretamente', 1),
('Home office autorizado', 1),
('Compensação de horas autorizada', 1),
('Esquecimento de registrar saída', 1),
('Esquecimento de registrar entrada', 1),
('Reunião externa autorizada', 1),
('Emergência familiar', 1),
('Problema no transporte público', 1),
('Licença previamente autorizada', 1);
```

---

**Total: 60+ motivos pré-definidos para cobrir todas as situações! 🎯**


